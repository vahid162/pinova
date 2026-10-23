# Browser redirect response review

The follow-up browser jobs on the response-observation change reached successful password HTTP responses, then Chromium reported `Network.getResponseBody: No resource with given identifier found`. The login application had already initiated its native post-login navigation; reading the departing page's response body introduced a test-driver race. This was an added assertion from the follow-up, not a production authentication error.

Retain the identifier response's HTTP/success-envelope assertions, because that step stays on the current page. For password completion retain HTTP 200, the original navigation assertion, and the existing downstream native cookie/account/proof checks. Remove only the newly introduced password-body read. Do not intercept or replace the authentication response, simulate cookies, increase product/test timeouts, add retries, or remove any original product assertion.

The full exact-head and separately merged-main matrices must pass before publication. Installed sites and customer accounts have not been modified by this source/test work.
