### ❌ PHPUnit — PHP 8.4 / pgsql

9 tests, 7 assertions, 2 failures, 1 error, 2 skipped · 0.4s

| Test | Location | Message |
| --- | --- | --- |
| SampleTest::testFailsWithDiff | `tests/SampleTest.php:13` | Arrays \| differ Failed asserting that two arrays are identical. |
| SampleTest::testThrows | `tests/SampleTest.php:18` | RuntimeException: Connection refused: \`db\` host second line |
| SampleTest::testAdd with data set "broken" | `tests/SampleTest.php:36` | Failed asserting that 2 is identical to 3. |

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
