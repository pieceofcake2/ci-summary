### ❌ PHPCS

7 errors, 0 warnings in 1 file.

| Location | Message | Rule |
| --- | --- | --- |
| `src/Bad.php:1` | Header blocks must be separated by a single blank line | PSR12.Files.FileHeader.SpacingAfterTagBlock |
| `src/Bad.php:2` | Each class must be in a namespace of at least one level (a top-level vendor name) | PSR1.Classes.ClassDeclaration.MissingNamespace |
| `src/Bad.php:2` | Opening brace of a class must be on the line after the definition | PSR2.Classes.ClassDeclaration.OpenBraceNewLine |
| `src/Bad.php:3` | Opening brace should be on a new line | Squiz.Functions.MultiLineFunctionDeclaration.BraceOnSameLine |
| `src/Bad.php:5` | Space before opening parenthesis of function call prohibited | PSR2.Methods.FunctionCallSignature.SpaceBeforeOpenBracket |
| `src/Bad.php:5` | Space after opening parenthesis of function call prohibited | PSR2.Methods.FunctionCallSignature.SpaceAfterOpenBracket |
| `src/Bad.php:5` | Expected 0 spaces before closing parenthesis; 1 found | PSR2.Methods.FunctionCallSignature.SpaceBeforeCloseBracket |

Run `vendor/bin/phpcbf` to fix the fixable ones.
