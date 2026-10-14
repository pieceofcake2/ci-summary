### ❌ PHPUnit — PHP 8.4 / pgsql

9 tests, 7 assertions, 2 failures, 1 error, 2 skipped · 0.4s

| Test | Message |
| --- | --- |
| SampleTest::testFailsWithDiff<br><code>tests/SampleTest.php:13</code> | Arrays \| differ Failed asserting that two arrays are identical. |
| SampleTest::testThrows<br><code>tests/SampleTest.php:18</code> | RuntimeException: Connection refused: \`db\` host second line |
| SampleTest::testAdd with data set "broken"<br><code>tests/SampleTest.php:36</code> | Failed asserting that 2 is identical to 3. |

<details><summary>Full messages</summary>

**SampleTest::testFailsWithDiff**

````
Arrays | differ
Failed asserting that two arrays are identical.
--- Expected
+++ Actual
@@ @@
 Array &0 (
     'a' => 1
-    'b' => 2
+    'b' => 3
 )
````

</details>

<details><summary>2 skipped</summary>

- SampleTest::testSkipped
- SampleTest::testIncomplete

</details>
