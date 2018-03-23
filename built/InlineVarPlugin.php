<?php

/**
 *
 * MIT License
 *
 * Copyright (c) 2018 Drienerlo Engineering Solutions
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * ----------------------------------------------------------
 *
 * All of the classes to this plugin are from https://github.com/Drenso/PhanExtensions
 * A few patches to this plugin were made at https://github.com/TysonAndre/Drenso_PhanExtensions
 */
namespace Drenso\PhanExtensions\Helper;

use Phan\CodeBase;
use Phan\Language\Context;
use Phan\Language\FQSEN\FullyQualifiedClassName;
use Phan\Language\Type;
use Phan\Language\Type\GenericArrayType;
use Phan\Language\Type\TemplateType;
use Phan\Language\UnionType;
use Phan\PluginV2;
use Phan\PluginV2\PluginAwarePostAnalysisVisitor;

class NamespaceChecker
{
  /**
   * @param PluginAwarePostAnalysisVisitor $visitor
   * @param CodeBase $codeBase
   * @param Context $context
   * @param string $unionTypeString
   * @param string $issueType
   * @param string $issueMessageFmt
   */
  public static function checkVisitor(PluginAwarePostAnalysisVisitor $visitor, CodeBase $codeBase, Context $context,
                                      string $unionTypeString, string $issueType, string $issueMessageFmt)
  {
    foreach (self::getMissingClasses($codeBase, $context, $unionTypeString) as $typeFQSEN) {
      $visitor->emit($issueType, $issueMessageFmt, [(string)$typeFQSEN]);
    }
  }

  /**
   * @param PluginV2 $plugin
   * @param CodeBase $codeBase
   * @param Context $context
   * @param string $unionTypeString
   * @param string $issueType
   * @param string $issueMessageFmt
   */
  public static function checkPlugin(PluginV2 $plugin, CodeBase $codeBase, Context $context,
                                     string $unionTypeString, string $issueType, string $issueMessageFmt)
  {
    foreach (self::getMissingClasses($codeBase, $context, $unionTypeString) as $typeFQSEN) {
      $plugin->emitIssue($codeBase, $context, $issueType, $issueMessageFmt, [(string)$typeFQSEN]);
    }
  }

  // yields a list of 0 or more types which are missing. Those may or may not have leading '\'es.
  private static function getMissingClasses(CodeBase $code_base, Context $context, string $unionTypeString) : \Generator {
    if (!$unionTypeString) {
      return;
    }
    // Filter for false positives
    // TODO: Use parameterFromCommentLine instead, which invokes UnionType::fromStringInContext for us.

    // This passed the regex, so fromStringInContext shouldn't throw
    $unionType = UnionType::fromStringInContext($unionTypeString, $context, Type::FROM_PHPDOC);

    // This check is based on \Phan\Analysis\ParameterTypesAnalyzer
    foreach ($unionType->getTypeSet() as $type) {
      // TODO: Handle ArrayShapeType
      while ($type instanceof GenericArrayType) {
        $type = $type->genericArrayElementType();
      }
      if ($type->isNativeType() ||($type->isSelfType() | $type->isStaticType())) {
        continue;
      }
      if ($type instanceof TemplateType) {
        // should be impossible, $context is a class declaration's context, not inside a method.
        continue;
      }
      // Should always be a class name
      $type_fqsen = $type->asFQSEN();
      if ($type_fqsen instanceof FullyQualifiedClassName && !$code_base->hasClassWithFQSEN($type_fqsen)) {
        yield $type_fqsen;
      }
    }
  }
}

namespace Drenso\PhanExtensions\Plugin\DocComment;

use Drenso\PhanExtensions\Helper\NamespaceChecker;
use Phan\Phan;
use Phan\CodeBase;
use Phan\Language\Element\Clazz;
use Phan\Language\Element\Comment;
use Phan\Language\UnionType;
use Phan\PluginV2;
use Phan\PluginV2\AnalyzeClassCapability;

class InlineVarPlugin extends PluginV2 implements AnalyzeClassCapability
{
  /**
   * @var array<string,bool> - A file can have more than one class, even though that goes against some style guides.
   *  So only analyze the file once, and assume there's only one namespace.
   */
  private $analyzedFileSet = [];

  public function analyzeClass(CodeBase $codeBase, Clazz $class)
  {
    // Check the file ref
    $file = $class->getFileRef();
    if ($file->isPHPInternal()) return;

    $fileName = $file->getFile();
    // Save time: skip files that won't be analyzed.
    if (Phan::isExcludedAnalysisFile($fileName)) {
      return;
    }
    if (array_key_exists($fileName, $this->analyzedFileSet)) {
      // TODO: Won't work with daemon mode without pcntl that well without new capabilities, but that's an edge case
      return;
    }
    $this->analyzedFileSet[$fileName] = true;
    $fileContents = file_get_contents($fileName);

    if (function_exists('token_get_all')) {
      $tokens = token_get_all($fileContents);
      // Set line number to found one
      $context = $class->getContext();
      $lineNumber = $context->getLineNumberStart();
      try {
        foreach ($tokens as $token) {
          if (!\is_array($token)) {
              continue;
          }
          // Filter comment tokens
          if ($token[0] != T_COMMENT && $token[0] !== T_DOC_COMMENT) continue;

          $class->getContext()->withLineNumberStart($token[2]);

          // Retrieve errors
          $this->findUsages($codeBase, $class, $token[1]);

        }
      } finally {
        // Restore line number to prevent errors
        $context->withLineNumberStart($lineNumber);
      }
    } else {
      // Forward complete file content
      $this->findUsages($codeBase, $class, $fileContents);
    }
  }

  // Copied from \Phan\Language\Element\Comment::param_comment_regex (without variadic/references for (at)param) with the following changes
  // 1. Make the name mandatory
  // 2. Make the union type mandatory
  // 3. Remove variadic/reference/param from the regex
  const var_comment_regex =
    '/@(?:phan-)?var\b\s*(' . UnionType::union_type_regex . ')\s*\\$' . Comment::WORD_REGEX . '/';

  /**
   * @param CodeBase $codeBase
   * @param Clazz $class
   * @param string $content
   */
  private function findUsages(CodeBase $codeBase, Clazz $class, string $content): void
  {
    \preg_match_all(self::var_comment_regex, $content, $matches);
    $results = $matches[1];
    foreach ($results as $match) {
      NamespaceChecker::checkPlugin($this, $codeBase, $class->getContext(), $match, "UndeclaredTypeInInlineVar",
          "The classlike {CLASS} in this \"var\" statement is undeclared (generated by InlineVar plugin)");
    }
  }
}

// Every plugin needs to return an instance of itself at the
// end of the file in which its defined.
return new InlineVarPlugin();
