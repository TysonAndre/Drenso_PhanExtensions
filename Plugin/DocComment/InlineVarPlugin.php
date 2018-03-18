<?php

namespace Drenso\PhanExtensions\Plugin\DocComment;

require_once __DIR__ . '/../../Helper/NamespaceChecker.php';

use Drenso\PhanExtensions\Helper\NamespaceChecker;
use Phan\CodeBase;
use Phan\Language\Element\Clazz;
use Phan\PluginV2;
use Phan\PluginV2\AnalyzeClassCapability;

class InlineVarPlugin extends PluginV2 implements AnalyzeClassCapability
{
  public function analyzeClass(CodeBase $code_base, Clazz $class)
  {
    // Check the file ref
    $file = $class->getFileRef();
    if ($file->isPHPInternal()) return;

    // Only check files in the src directory
    if (strpos($file, 'src/') === 0) {

      preg_match_all('/\/\*\*? \@var (\w*)\|?(\[\]|\w*)? ([\$\w]+) \*?\*\//', file_get_contents($file->getFile()), $matches);
      $results = array_merge($matches[1], $matches[2]);
      foreach ($results as $match) {
        NamespaceChecker::checkPlugin($this, $code_base, $class->getContext(), $match, "VarStatementNotImported",
            "The classlike/namespace {CLASS} in the \"var\" statement was never imported (generated by InlineVar plugin)");
      }
    }
  }
}

// Every plugin needs to return an instance of itself at the
// end of the file in which its defined.
return new InlineVarPlugin();