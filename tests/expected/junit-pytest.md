### ❌ pytest

6 tests, 3 failures, 1 skipped · 0.0s

| Test | Message |
| --- | --- |
| test\_cart::test\_total\_with\_discount<br><code>tests/test\_cart.py:13</code> | AssertionError: discount &lt;10%&gt; not applied assert 30 == 27 + where 30 = total(\[10, 20\]) |
| test\_cart::test\_parse\_error<br><code>tests/test\_cart.py:17</code> | ValueError: bad \| input \`x\` |
| test\_cart::test\_param\[2\]<br><code>tests/test\_cart.py:27</code> | assert 2 == 1 |

<details><summary>Full messages</summary>

**test\_cart::test\_total\_with\_discount**

````
def test_total_with_discount():
>       assert total([10, 20]) == 27, "discount <10%> not applied"
E       AssertionError: discount <10%> not applied
E       assert 30 == 27
E        +  where 30 = total([10, 20])
````

**test\_cart::test\_parse\_error**

````
def test_parse_error():
>       raise ValueError("bad | input `x`")
E       ValueError: bad | input `x`
````

**test\_cart::test\_param\[2\]**

````
n = 2

    @pytest.mark.parametrize("n", [1, 2])
    def test_param(n):
>       assert n == 1
E       assert 2 == 1
````

</details>

<details><summary>1 skipped</summary>

- test\_cart::test\_checkout — needs the payment sandbox

</details>
