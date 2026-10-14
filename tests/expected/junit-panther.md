### ❌ E2E

4 tests, 3 assertions, 1 failure, 2 errors, 0 skipped · 41.2s

Screenshots, logs and other files of the failed tests are in the `screenshots` artifact.

| Test | Message |
| --- | --- |
| CheckoutTest::testPayByCard<br><code>tests/E2E/CheckoutTest.php:27</code> | TimeoutException |
| CheckoutTest::testPayButton<br><code>tests/E2E/CheckoutTest.php:34</code> | NoSuchElementException: no such element: Unable to locate element: {"method":"css selector","selector":"#pay &gt; button\[type=\\"submit\\"\]"} |
| CheckoutTest::testReceipt<br><code>tests/E2E/CheckoutTest.php:44</code> | The receipt shows the wrong total. Screenshot: var/error-screenshots/receipt.png |

<details><summary>Full messages</summary>

**CheckoutTest::testPayButton**

````
Facebook\WebDriver\Exception\NoSuchElementException: no such element: Unable to locate element: {"method":"css selector","selector":"#pay > button[type=\"submit\"]"}
  (Session info: chrome=155.0.8059.40)
````

**CheckoutTest::testReceipt**

````
The receipt shows the wrong total. Screenshot: var/error-screenshots/receipt.png
Failed asserting that 'Order #1042\n
Thank you for your order\n
Items\n
Coffee beans (1 kg) × 2\n
Paper filters × 1\n
Subtotal ¥4,980\n
Shipping ¥600\n
Total ¥5,580\n
A receipt was sent to jane@example.com.\n
Back to the shop' contains "Total ¥5,380".
````

</details>
