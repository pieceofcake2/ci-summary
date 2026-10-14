### ❌ PHPCS

7 errors, 0 warnings in 1 file.

| Location | Message |
| --- | --- |
| <code>src/Bad.php:1</code><br><sub>PSR12.Files.FileHeader.SpacingAfterTagBlock</sub> | Header blocks must be separated by a single blank line |
| <code>src/Bad.php:2</code><br><sub>PSR1.Classes.ClassDeclaration.MissingNamespace</sub> | Each class must be in a namespace of at least one level (a top-level vendor name) |
| <code>src/Bad.php:2</code><br><sub>PSR2.Classes.ClassDeclaration.OpenBraceNewLine</sub> | Opening brace of a class must be on the line after the definition |
| <code>src/Bad.php:3</code><br><sub>Squiz.Functions.MultiLineFunctionDeclaration.BraceOnSameLine</sub> | Opening brace should be on a new line |
| <code>src/Bad.php:5</code><br><sub>PSR2.Methods.FunctionCallSignature.SpaceBeforeOpenBracket</sub> | Space before opening parenthesis of function call prohibited |
| <code>src/Bad.php:5</code><br><sub>PSR2.Methods.FunctionCallSignature.SpaceAfterOpenBracket</sub> | Space after opening parenthesis of function call prohibited |
| <code>src/Bad.php:5</code><br><sub>PSR2.Methods.FunctionCallSignature.SpaceBeforeCloseBracket</sub> | Expected 0 spaces before closing parenthesis; 1 found |

Run `vendor/bin/phpcbf` to fix the fixable ones.
