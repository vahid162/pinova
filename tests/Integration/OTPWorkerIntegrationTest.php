<?php

declare(strict_types=1);

namespace Pinova\Tests\Integration;

use Pinova\Install;
use Pinova\Logging\LogRepository;
use Pinova\Models\OTP;
use Pinova\Services\OTPService;
use Pinova\Services\RateLimitService;
use WP_Error;
use WP_UnitTestCase;

final class OTPWorkerIntegrationTest extends WP_UnitTestCase {
	private int $mail_calls = 0;
	private array $flows = [];

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		Install::create_tables();
	}

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->delete($wpdb->prefix . 'pinova_rate_limits', ['scope'=>'log_auth_request_failed']);
		$this->mail_calls = 0;
		$this->flows = [];
		OTP::query()->delete();
		LogRepository::delete_all();
		update_option('pinova_logging', ['minimum_level'=>'info','retention_days'=>14]);
		add_action('pinova_otp_delivery', [OTPService::class,'deliver_queued']);
		add_filter('pre_wp_mail', [$this,'intercept_mail']);
	}

	public function tear_down(): void {
		remove_filter('pre_wp_mail', [$this,'intercept_mail']);
		wp_unschedule_hook('pinova_otp_delivery');
		wp_unschedule_hook('pinova_worker_other');
		foreach($this->flows as $flow){RateLimitService::retire_queued_otp($flow);}
		OTP::query()->delete();
		LogRepository::delete_all();
		delete_option('pinova_logging');
		parent::tear_down();
	}

	public function intercept_mail(): bool {
		++$this->mail_calls;
		return true;
	}

	public function test_only_due_otp_events_are_processed_and_other_events_are_preserved(): void {
		$due=$this->queue('due@example.test');
		$future=$this->queue('future@example.test',time()+60);
		wp_schedule_single_event(time()-1,'pinova_worker_other',['foreign-job']);
		$other=wp_get_scheduled_event('pinova_worker_other',['foreign-job']);
		self::assertSame(1,OTPService::run_due_delivery());
		self::assertSame(1,$this->mail_calls);
		self::assertFalse(wp_next_scheduled('pinova_otp_delivery',[$due]));
		self::assertNotFalse(wp_next_scheduled('pinova_otp_delivery',[$future]));
		self::assertEquals($other,wp_get_scheduled_event('pinova_worker_other',['foreign-job']));
		self::assertSame(1,OTP::query()->where('flow_id',$due)->count());
		self::assertSame(0,OTPService::run_due_delivery());
		self::assertSame(1,$this->mail_calls);
	}

	public function test_three_job_batch_leaves_the_remaining_job_scheduled(): void {
		foreach(range(1,4) as $index){$this->queue('batch-'.$index.'@example.test');}
		self::assertSame(3,OTPService::run_due_delivery());
		self::assertSame(3,$this->mail_calls);
		self::assertSame(1,OTPService::run_due_delivery());
		self::assertSame(4,$this->mail_calls);
		self::assertSame(0,OTPService::run_due_delivery());
	}

	public function test_failed_unschedule_never_dispatches_a_provider_or_removes_the_job(): void {
		$flow=$this->queue('unschedule@example.test');
		$deny=static fn()=>new WP_Error('blocked','synthetic unschedule failure');
		add_filter('pre_unschedule_event',$deny);
		try {
			OTPService::run_due_delivery();
			self::fail('The unschedule failure must stop delivery.');
		} catch(\Exception $exception){
			self::assertSame('The due OTP event could not be unscheduled.',$exception->getMessage());
		} finally {remove_filter('pre_unschedule_event',$deny);}
		self::assertSame(0,$this->mail_calls);
		self::assertNotFalse(wp_next_scheduled('pinova_otp_delivery',[$flow]));
	}

	public function test_expired_unclaimed_queue_is_observed_without_delivery_or_identity_context(): void {
		global $wpdb;
		$flow=$this->queue('expired@example.test');
		$wpdb->update($wpdb->prefix.'pinova_rate_limits',['reset_at'=>gmdate('Y-m-d H:i:s',time()-1)],['scope'=>$flow]);
		self::assertSame(1,OTPService::run_due_delivery());
		self::assertSame(0,$this->mail_calls);
		self::assertSame(0,OTP::query()->count());
		$rows=LogRepository::paginate(1,10,'',['event'=>'auth.request_failed'])['rows'];
		self::assertCount(1,$rows);
		self::assertSame($flow,$rows[0]['flow_id']);
		self::assertNull($rows[0]['user_id']);
		$context=json_decode($rows[0]['context'],true);
		self::assertSame('queue_expired',$context['reason']);
		self::assertSame('queued_otp',$context['operation']);
		self::assertStringNotContainsString('expired@example.test',$rows[0]['context']);
		RateLimitService::observe_expired_queued_otp($flow);
		self::assertSame(1,LogRepository::paginate(1,10,'',['event'=>'auth.request_failed'])['total']);
	}

	public function test_missing_delivery_callback_preserves_pending_jobs(): void {
		$flow=$this->queue('callback@example.test');
		remove_action('pinova_otp_delivery',[OTPService::class,'deliver_queued']);
		try {
			OTPService::run_due_delivery();
			self::fail('Missing delivery callback must reject the worker.');
		} catch(\Exception $exception) {
			self::assertSame('The OTP queue or delivery callback is unavailable.',$exception->getMessage());
		} finally {add_action('pinova_otp_delivery',[OTPService::class,'deliver_queued']);}
		self::assertNotFalse(wp_next_scheduled('pinova_otp_delivery',[$flow]));
		self::assertSame(0,$this->mail_calls);
	}

	public function test_finished_and_claimed_records_are_not_reported_as_expired_queue(): void {
		global $wpdb;
		foreach(['delivered','processing','processing:0123456789abcdef:ciphertext','cancelled:0123456789abcdef:ciphertext'] as $index=>$payload){
			$flow=$this->queue('finished-'.$index.'@example.test');
			$wpdb->update($wpdb->prefix.'pinova_rate_limits',['reset_at'=>gmdate('Y-m-d H:i:s',time()-1),'payload'=>$payload],['scope'=>$flow]);
			RateLimitService::observe_expired_queued_otp($flow);
		}
		self::assertSame(0,LogRepository::paginate(1,10,'',['event'=>'auth.request_failed'])['total']);
		self::assertSame(0,$this->mail_calls);
	}

	public function test_diagnostic_query_failure_is_silent_and_restores_database_error_policy(): void {
		global $wpdb;
		$flow=$this->queue('diagnostic@example.test');
		$old_policy=$wpdb->suppress_errors(false);
		$deny=static function(string $query): string {
			return str_contains($query,'SELECT 1 FROM') && str_contains($query,"`payload` NOT IN ('delivered', 'processing')")
				? 'SELECT 1 FROM pinova_missing_diagnostic_table' : $query;
		};
		add_filter('query',$deny);
		try {
			ob_start();
			RateLimitService::observe_expired_queued_otp($flow);
			self::assertSame('',ob_get_clean());
			self::assertFalse($wpdb->suppress_errors);
		} finally {
			remove_filter('query',$deny);
			$wpdb->suppress_errors($old_policy);
			$wpdb->last_error='';
		}
		self::assertSame(1,OTPService::run_due_delivery());
		self::assertSame(1,$this->mail_calls);
	}

	public function test_recurring_or_malformed_jobs_are_not_executed_or_removed(): void {
		$flow=$this->queue('recurring@example.test',time()+60);
		self::assertTrue(wp_schedule_event(time()-1,'hourly','pinova_otp_delivery',[$flow]));
		self::assertTrue(wp_schedule_single_event(time()-1,'pinova_otp_delivery',['invalid-flow']));
		self::assertSame(0,OTPService::run_due_delivery());
		self::assertSame(0,$this->mail_calls);
		self::assertSame('hourly',wp_get_scheduled_event('pinova_otp_delivery',[$flow])->schedule);
		self::assertNotFalse(wp_next_scheduled('pinova_otp_delivery',['invalid-flow']));
	}

	private function queue(string $email,?int $timestamp=null): string {
		self::factory()->user->create(['user_email'=>$email,'role'=>'subscriber']);
		[$flow]=RateLimitService::decoy_flow($email,'authenticate','192.0.2.150');
		$this->flows[]=$flow;
		self::assertTrue(wp_schedule_single_event($timestamp??time()-1,'pinova_otp_delivery',[$flow]));
		return $flow;
	}
}
