<!doctype html>
<html lang="fa" dir="rtl">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<title>کد تایید</title>
</head>
<body dir="rtl" style="font-family:'IRANSans','Vazir','Noto Sans Arabic','Geeza Pro',Tahoma,Arial,sans-serif;;direction:rtl;margin:0;padding:0;background-color:#f3f4f6;">

<table dir="rtl" role="presentation" cellpadding="0" cellspacing="0" width="100%" bgcolor="#f3f4f6" style="padding: 25px">
	<tr>
		<td align="center">
			<table dir="rtl" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:640px;margin:0 auto;background:#ffffff;color: #344054; padding:25px;">
				<!-- header -->
				<tr>
					<td style="text-align: center;">
						<img alt="pinova" src="{{logo_url}}" width="100" class="CToWUd" data-bit="iit">
					</td>
				</tr>

				<!-- content -->
				<tr>
					<td style="padding-top: 25px; border-radius:8px;-webkit-border-radius:8px;">
						<div style="direction:rtl;text-align:right;">
							<p style="margin:0 0 14px 0;font-size:16px;line-height:24px;">
								سلام کاربر عزیز،
							</p>

							<p style="margin:0 0 20px 0;font-size:15px;line-height:22px;">
								برای ورود به حساب کاربری خود در {{site_name}} نیاز به تأیید هویت دارید. کد یک‌بارمصرف شما به شرح زیر است:
							</p>

							<!-- code block -->
							<table dir="rtl" role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:20px 0;">
								<tr>
									<td align="center" style="background:#F0F7FF;padding:18px;">
                                      <span style="font-size:36px;font-weight:700; letter-spacing:14px;color:#004FA3">
                                        {{code}}
                                      </span>
									</td>
								</tr>
							</table>

							<p style="margin:0 0 18px 0;font-size:14px;">
								این کد تا {{expires_in_minutes}} دقیقه آینده معتبر خواهد بود. لطفاً آن را در صفحه‌ی ورود وارد کنید.
							</p>

							<p style="margin:0 0 18px 0;font-size:14px;">
								با احترام،
								<br>
								{{site_name}}
							</p>

							<p style="margin:10px 20px;padding-top: 25px;font-size:12px;line-height:20px;border-top: 1px solid #EAECF0; color: #667085">
								این ایمیل برای ورود به حساب کاربری شما در سایت {{site_name}} ارسال شده است. اگر این درخواست توسط شما انجام نشده، لطفاً ایمیل را نادیده بگیرید.
							</p>
							<p style="margin:10px 20px;font-size:12px;line-height:20px;color: #667085">
								به خاطر داشته باشید کد یکبار مصرف محرمانه است؛ آن را با هیچ‌کس به اشتراک نگذارید. {{site_name}} هرگز این کد را از شما درخواست نخواهد کرد.
							</p>
						</div>
					</td>
				</tr>

				<!-- footer -->
				<tr>
					<td style="padding-top: 25px">
						<table dir="rtl" role="presentation" width="100%" cellspacing="0" cellpadding="0">
							<tr>
								<td style="font-size:12px">
									<a href="{{site_url}}">
										<img alt="pinova" src="{{logo_url}}" width="55"  class="CToWUd" data-bit="iit">
									</a>
								</td>

								<td align="left" style="font-size:12px;">
									<a href="{{site_url}}">
										<img alt="pinova" src="{{web_icon}}" width="20" class="CToWUd" data-bit="iit">
									</a>
								</td>
							</tr>
						</table>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
</body>
</html>
