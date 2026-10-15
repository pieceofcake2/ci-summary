### ❌ E2E — PHP 8.4

9 tests, 8 assertions, 4 failures, 1 error, 1 skipped · 5.9s

Screenshots, page HTML and server logs of the failed tests are in the [`e2e-artifacts-php8.4`](https://github.com/acme/shop/actions/runs/123456789) artifact.

| Test | Message |
| --- | --- |
| ContactFormTest::testSeeText<br><code>tests/E2E/ContactFormTest.php:13</code> | The page / does not show "Thank you for your message.". |
| ContactFormTest::testCurrentPath<br><code>tests/E2E/ContactFormTest.php:19</code> | Unexpected current path. Failed asserting that two strings are identical. |
| ContactFormTest::testMissingField<br><code>tests/E2E/ContactFormTest.php:25</code> | NoSuchElementException: No field with the name, id or label "Phone number" on /contacts/add |
| ContactFormTest::testWaitForText<br><code>tests/E2E/ContactFormTest.php:31</code> | Timed out waiting for "Never shows up" on /. |
| ContactFormTest::testMobileOverflow<br><code>tests/E2E/ContactFormTest.php:37</code> | The page /contacts/wide is wider (816px) than the viewport (390px). Failed asserting that 816 is equal to 390 or is less than 390. |

<details><summary>Full messages</summary>

**ContactFormTest::testSeeText**

````
The page / does not show "Thank you for your message.".
Failed asserting that 'Welcome to the fixture app\n
Contact us\n
Don't miss "this" page\n
Remember me\n
Wide page\n
Open the help in a new window\n
Load more\n
\n
Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/155.0.0.0 Safari/537.36' contains "Thank you for your message.".
````

**ContactFormTest::testCurrentPath**

````
Unexpected current path.
Failed asserting that two strings are identical.
--- Expected
+++ Actual
@@ @@
-'/contacts/thanks'
+'/contacts/add'
````

</details>

<details><summary>1 skipped</summary>

- ContactFormTest::testSkipped

</details>
