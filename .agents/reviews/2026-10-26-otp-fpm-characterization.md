# OTP response-boundary characterization — 2026-10-26

This records the pre-implementation PHP-FPM experiment required by the approved immediate-delivery plan. It establishes the candidate response lifecycle, not production delivery, queue correctness, resource acceptance, or release readiness.

The source is `0eab32d1c4c12d19fc0d0cdcfe3ea6526e3bd215`, tree `b2c699178d4a7bca85e296a20f686cfc2d68da7c`. Its `push` [Quality run 40245453367](https://github.com/vahid162/pinova/actions/runs/40245453367) ran actual WordPress 6.8 REST serialization and shutdown hooks through isolated native Nginx/PHP-FPM. WordPress's REST-server file SHA-256 was `d76f51f1c63eaef78535e62d187ac9a3eaccfb070308713e784814d1363e898b`. Both pools used the native 4,096-byte output buffer.

| Observation | PHP 8.3.36, job 121326277173 | PHP 8.4.27, job 121326277050 |
| --- | ---: | ---: |
| Healthy complete HTTP response | 0.026949 s | 0.047500 s |
| Same-process synthetic provider tail | 3.000235 s | 3.000161 s |
| Follow-up request waiting for occupied one-child pool | 3.001094 s | 3.000616 s |
| Occupancy with one-second timeout and finished-request tracking off | 4.501374 s | 4.500817 s |
| Occupancy with finished-request tracking on | 1.023344 s | 1.019139 s |

Server markers established successful genuine FPM completion before the synthetic account/provider work, with the same worker PID. The independent HTTP client did not wait for the three-second provider. This is a server-side completion boundary; proxy/network delay can still postpone browser receipt.

Both jobs passed rejection cases for error/replaced/mutated/manually served responses, partial output, removed output observers, late shutdown output including the same flush priority, and fatal execution/shutdown. Nonempty initial buffers and foreign handlers were rejected. A genuinely false second native completion, disabled completion, and CLI execution also rejected fast work. Eligible/decoy synthetic responses matched. The finite fixture fallback marker is not evidence of actual durable queue recovery.

The initial run `40245242311` failed because the disposable PHP 8.4 FPM package repository was missing, a PHP literal needed a scoped ShellCheck annotation, and the DB-free core bootstrap omitted `WP_List_Util`. Those fixture/provisioning errors were corrected before the successful run; they were not installed-site defects.

No plugin runtime, installed site, database, scheduler, or SMS provider was changed by this experiment. The test-only candidate must be replaced by the actual production boundary adapter in final verification. Full plugin/database concurrency and provider-budget tests, representative-profile FPM cost measurement, review, publication, staging, and the approved production canary remain separate gates. These small DB-free timing and memory observations must not be presented as the site's expected resource use or SMS delivery time.
