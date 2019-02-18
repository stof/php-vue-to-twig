<?php declare(strict_types=1);

namespace Paneon\VueToTwig;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Exception;
use Paneon\VueToTwig\Models\Component;
use Paneon\VueToTwig\Models\Replacements;
use Paneon\VueToTwig\Models\Slot;
use Paneon\VueToTwig\Utils\TwigBuilder;
use Psr\Log\LoggerInterface;

class Compiler
{

    /** @var Component[] */
    protected $components;

    /** @var DOMDocument */
    protected $document;

    /** @var DOMText */
    protected $lastCloseIf;

    /** @var LoggerInterface */
    protected $logger;

    /** @var string[] */
    protected $banner;

    /**
     * @var TwigBuilder
     */
    protected $builder;

    protected $replaceVariables = [];

    protected $variables = [];

    protected $stripWhitespace = true;

    public function __construct(DOMDocument $document, LoggerInterface $logger)
    {
        $this->builder = new TwigBuilder();
        $this->document = $document;
        $this->logger = $logger;
        $this->lastCloseIf = null;
        $this->components = [];
        $this->banner = [];

        $this->logger->debug("\n--------- New Compiler Instance ----------\n");
    }

    /**
     * @param string|string[] $strings
     */
    public function setBanner($strings): void
    {
        if (!is_array($strings)) {
            $strings = [$strings];
        }

        $this->banner = $strings;
    }

    /**
     * @throws Exception
     */
    public function convert(): string
    {
        $templateElement = $this->document->getElementsByTagName('template')->item(0);

        if (!$templateElement) {
            throw new Exception('The template file does not contain a template tag.');
        }

        $rootNode = $this->getRootNode($templateElement);
        $resultNode = $this->convertNode($rootNode);
        $html = $this->document->saveHTML($resultNode);

        $html = $this->addVariableBlocks($html);
        $html = $this->replacePlaceholders($html);

        if ($this->stripWhitespace) {
            $html = $this->stripWhitespace($html);
        }

        if (!empty($this->banner)) {
            $html = $this->addBanner($html);
        }

        return $html;
    }

    public function convertNode(DOMNode $node): DOMNode
    {
        switch ($node->nodeType) {
            case XML_TEXT_NODE:
                $this->logger->debug('Text node found', ['name' => $node->nodeName]);
            // fall through to next case, because we don't need to handle either of these node-types
            case XML_COMMENT_NODE:
                $this->logger->debug('Comment node found', ['name' => $node->nodeName]);
                return $node;
            case XML_ELEMENT_NODE:
                /** @var DOMElement $node */
                $this->replaceShowWithIf($node);
                $this->handleIf($node);
                break;
            case XML_HTML_DOCUMENT_NODE:
                $this->logger->warning("Document node found.");
                break;
        }

        $this->handleFor($node);
        $this->stripEventHandlers($node);
        //$this->handleRawHtml($node, $data);

        $this->handleDefaultSlot($node);

        /*
         * Registered Component
         */
        if (in_array($node->nodeName, array_keys($this->components))) {
            $matchedComponent = $this->components[$node->nodeName];
            $usedComponent = new Component($matchedComponent->getName(), $matchedComponent->getPath());

            if ($node->hasAttributes()) {
                /** @var DOMAttr $attribute */
                foreach ($node->attributes as $attribute) {
                    if (strpos($attribute->name, 'v-bind:') === 0 || strpos($attribute->name, ':') === 0) {
                        $name = substr($attribute->name, strpos($attribute->name, ':') + 1);
                        $value = $this->refactorTemplateString($attribute->value);

                        $usedComponent->addProperty($name, $value, true);
                    } else {
                        $usedComponent->addProperty($attribute->name, '"'.$attribute->value.'"', false);
                    }
                }
            }

            /*
             * Slots (Default)
             */
            if ($node->hasChildNodes()) {
                $innerHtml = $this->innerHtmlOfNode($node);
                $this->logger->debug('Add default slot:', [
                    'nodeValue' => $node->nodeValue,
                    'innerHtml' => $innerHtml,
                ]);

                $slot = $usedComponent->addDefaultSlot($innerHtml);

                $this->addReplaceVariable($slot->getSlotContentVariableString(), $slot->getValue());
            }

            /*
             * Include Partial
             */
            $include = $this->document->createTextNode(
                $this->builder->createIncludePartial(
                    $usedComponent->getPath(),
                    $usedComponent->getProperties()
                )
            );

            $node->parentNode->insertBefore($include, $node);

            if ($usedComponent->hasSlots()) {

                foreach ($usedComponent->getSlots() as $slotName => $slot) {
                    // Add variable which contains the content (set)
                    $openSet = $this->document->createTextNode(
                        $this->builder->createSet($slot->getSlotValueName())
                    );
                    $node->parentNode->insertBefore($openSet, $include);

                    $setContent = $this->document->createTextNode($slot->getSlotContentVariableString());

                    $node->parentNode->insertBefore($setContent, $include);

                    // Close variable (endset)
                    $closeSet = $this->document->createTextNode(
                        $this->builder->closeSet()
                    );
                    $node->parentNode->insertBefore($closeSet, $include);
                }

            }

            // Remove original node
            $node->parentNode->removeChild($node);

            return $node;
        }

        $this->handleAttributeBinding($node);

        foreach (iterator_to_array($node->childNodes) as $childNode) {
            $this->convertNode($childNode);
        }

        return $node;
    }

    public function replaceShowWithIf(DOMElement $node): void
    {
        if ($node->hasAttribute('v-show')) {
            $node->setAttribute('v-if', $node->getAttribute('v-show'));
            $node->removeAttribute('v-show');
        }
    }

    private function handleAttributeBinding(DOMElement $node)
    {
        /** @var DOMAttr $attribute */
        foreach (iterator_to_array($node->attributes) as $attribute) {

            if (strpos($attribute->name, 'v-bind:') !== 0 && strpos($attribute->name, ':') !== 0) {
                $this->logger->debug("- skip: ".$attribute->name);
                continue;
            }

            $name = substr($attribute->name, strpos($attribute->name, ':') + 1);
            $value = $attribute->value;
            $this->logger->debug('- handle: '.$name.' = '.$value);


            switch ($name) {
                case 'key':
                    // Not necessary in twig
                    break;
                case 'style':
                    break;
                case 'class':
                    break;
                default:
                    if ($value === 'true') {
                        $this->logger->debug('- setAttribute '.$name);
                        $node->setAttribute($name, $name);
                    } else {
                        $this->logger->debug('- setAttribute "'.$name.'" with value');
                        $node->setAttribute(
                            $name,
                            Replacements::getSanitizedConstant('DOUBLE_CURLY_OPEN').
                            $value.
                            Replacements::getSanitizedConstant('DOUBLE_CURLY_CLOSE')
                        );
                    }
            }

            if (is_array($value)) {
                if ($name === 'style') {
                    $styles = [];
                    foreach ($value as $prop => $setting) {
                        if ($setting) {
                            $prop = strtolower(preg_replace('/([A-Z])/', '-$1', $prop));
                            $styles[] = sprintf('%s:%s', $prop, $setting);
                        }
                    }
                    $node->setAttribute($name, implode(';', $styles));
                } elseif ($name === 'class') {
                    $classes = [];
                    foreach ($value as $className => $setting) {
                        if ($setting) {
                            $classes[] = $className;
                        }
                    }
                    $node->setAttribute($name, implode(' ', $classes));
                }
            } /*
             * <div :class="`abc ${someDynamicClass}`">
             */
            elseif (preg_match('/^`(?P<content>.+)`$/', $value, $matches)) {
                $templateStringContent = $matches['content'];

                $templateStringContent = preg_replace(
                    '/\$\{(.+)\}/',
                    '{{ $1 }}',
                    $templateStringContent
                );

                $node->setAttribute($name, $templateStringContent);
            } else {
                $this->logger->debug('- No Handling for: '.$value);
            }

            $this->logger->debug('=> remove original '.$attribute->name);
            $node->removeAttribute($attribute->name);
        }
    }

    private function handleIf(DOMElement $node): void
    {
        if (!$node->hasAttribute('v-if') &&
            !$node->hasAttribute('v-else-if') &&
            !$node->hasAttribute('v-else')) {
            return;
        }

        if ($node->hasAttribute('v-if')) {
            $condition = $node->getAttribute('v-if');
            $condition = $this->sanitizeCondition($condition);

            // Open with if
            $openIf = $this->document->createTextNode($this->builder->createIf($condition));
            $node->parentNode->insertBefore($openIf, $node);

            // Close with endif
            $closeIf = $this->document->createTextNode($this->builder->createEndIf());
            $node->parentNode->insertBefore($closeIf, $node->nextSibling);

            $this->lastCloseIf = $closeIf;

            $node->removeAttribute('v-if');
        } elseif ($node->hasAttribute('v-else-if')) {
            $condition = $node->getAttribute('v-else-if');
            $condition = $this->sanitizeCondition($condition);

            // Replace old endif with else
            $this->lastCloseIf->textContent = $this->builder->createElseIf($condition);

            // Close with new endif
            $closeIf = $this->document->createTextNode($this->builder->createEndIf());
            $node->parentNode->insertBefore($closeIf, $node->nextSibling);
            $this->lastCloseIf = $closeIf;

            $node->removeAttribute('v-else-if');
        } elseif ($node->hasAttribute('v-else')) {
            // Replace old endif with else
            $this->lastCloseIf->textContent = $this->builder->createElse();

            // Close with new endif
            $closeIf = $this->document->createTextNode($this->builder->createEndIf());
            $node->parentNode->insertBefore($closeIf, $node->nextSibling);
            $this->lastCloseIf = $closeIf;

            $node->removeAttribute('v-else');
        }
    }

    private function handleFor(DOMElement $node)
    {
        /** @var DOMElement $node */
        if (!$node->hasAttribute('v-for')) {
            return;
        }

        [$forLeft, $listName] = explode(' in ', $node->getAttribute('v-for'));

        /*
         * Variations:
         * (1) item in array
         * (2)
         * (3) key, item in array
         * (4) key, item, index in object
         */

        // (2)
        if (preg_match('/(\d+)/', $listName)) {
            $listName = '1..'.$listName;
        }

        // (1)
        $forCommand = $this->builder->createForItemInList($forLeft, $listName);

        if (strpos($forLeft, ',')) {
            $forLeft = str_replace('(', '', $forLeft);
            $forLeft = str_replace(')', '', $forLeft);

            $forLeftArray = explode(',', $forLeft);

            $forValue = $forLeftArray[0];
            $forKey = $forLeftArray[1];
            $forIndex = $forLeftArray[2] ?? null;

            // (3)
            $forCommand = $this->builder->createFor($listName, $forValue, $forKey);

            if ($forIndex) {
                // (4)
                $forCommand .= $this->builder->createVariable($forIndex, 'loop.index0');
            }
        }

        $startFor = $this->document->createTextNode($forCommand);
        $node->parentNode->insertBefore($startFor, $node);

        // End For
        $endFor = $this->document->createTextNode($this->builder->createEndFor());
        $node->parentNode->insertBefore($endFor, $node->nextSibling);

        $node->removeAttribute('v-for');
    }

    private function stripEventHandlers(DOMElement $node)
    {
        /** @var DOMAttr $attribute */
        foreach ($node->attributes as $attribute) {
            if (strpos($attribute->name, 'v-on:') === 0 || strpos($attribute->name, '@') === 0) {
                $node->removeAttribute($attribute->name);
            }
        }
    }

    /**
     * @throws Exception
     */
    private function getRootNode(DOMElement $element): \DOMNode
    {
        /** @type \DOMNode[] */
        $nodes = iterator_to_array($element->childNodes);

        $tagNodes = 0;
        $firstTagNode = null;

        /** @var DOMNode $node */
        foreach ($nodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                continue;
            } elseif (in_array($node->nodeName, ['script', 'style'])) {
                continue;
            } else {
                $tagNodes++;
                $firstTagNode = $node;
            }
        }

        if ($tagNodes > 1) {
            //throw new Exception('Template should have only one root node');
        }

        return $firstTagNode;
    }

    protected function sanitizeCondition(string $condition)
    {
        $condition = str_replace('&&', 'and', $condition);
        $condition = str_replace('||', 'or', $condition);

        foreach (Replacements::getConstants() as $constant => $value) {
            $condition = str_replace($value, Replacements::getSanitizedConstant($constant), $condition);
        }

        return $condition;
    }

    protected function replacePlaceholders(string $string)
    {
        foreach (Replacements::getConstants() as $constant => $value) {
            $string = str_replace(Replacements::getSanitizedConstant($constant), $value, $string);
        }

        foreach ($this->replaceVariables as $safeString => $value) {
            $string = str_replace($safeString, $value, $string);
        }

        return $string;
    }

    public function registerComponent(string $componentName, string $componentPath)
    {
        $this->components[strtolower($componentName)] = new Component($componentName, $componentPath);
    }

    protected function addSingleLineBanner(string $html)
    {
        return $this->builder->createComment(implode('', $this->banner))."\n".$html;
    }

    protected function addBanner(string $html)
    {
        if (count($this->banner) === 1) {
            return $this->addSingleLineBanner($html);
        }

        $bannerLines = ['{#'];

        foreach ($this->banner as $line) {
            $bannerLines[] = ' # '.$line;
        }

        $bannerLines[] = ' #}';

        $html = implode(PHP_EOL, $bannerLines).PHP_EOL.$html;

        return $html;
    }

    public function refactorTemplateString($value)
    {
        if (preg_match('/^`(?P<content>.+)`$/', $value, $matches)) {
            $templateStringContent = '"'.$matches['content'].'"';
            $value = preg_replace(
                '/\$\{(.+)\}/',
                '{{ $1 }}',
                $templateStringContent
            );
        }

        return $value;
    }

    public function stripWhitespace($html)
    {
        $html = preg_replace('/(\s)+/s', '\\1', $html);
        $html = str_replace("\n", '', $html);

        // Trim node text
        $html = preg_replace('/\>[^\S ]+/s', ">", $html);
        $html = preg_replace('/[^\S ]+\</s', "<", $html);

        $html = preg_replace('/> </s', '><', $html);
        $html = preg_replace('/} </s', '}<', $html);
        $html = preg_replace('/> {/s', '>{', $html);
        $html = preg_replace('/} {/s', '}{', $html);

        return $html;
    }

    /**
     * @param bool $stripWhitespace
     *
     * @return Compiler
     */
    public function setStripWhitespace(bool $stripWhitespace): Compiler
    {
        $this->stripWhitespace = $stripWhitespace;

        return $this;
    }

    public function innerHtmlOfNode(DOMNode $element)
    {
        $innerHTML = "";
        $children = $element->childNodes;

        foreach ($children as $child) {
            $innerHTML .= trim($element->ownerDocument->saveHTML($child));
        }

        return $innerHTML;
    }

    protected function addReplaceVariable($safeString, $value)
    {
        $this->replaceVariables[$safeString] = $value;
    }

    protected function addVariable($name, $value)
    {
        if (isset($this->variables[$name])) {
            throw new Exception("The variable $name is already registered.", 500);
        }

        $this->variables[$name] = $value;
    }

    protected function addVariableBlocks(string $string): string
    {
        $blocks = [];

        foreach ($this->variables as $varName => $varValue) {
            $blocks[] = $this->builder->createMultilineVariable($varName, $varValue);
        }

        return implode('', $blocks). $string;
    }

    protected function handleDefaultSlot(DOMElement $node)
    {
        if ($node->nodeName !== 'slot') {
            return;
        }

        $slotFallback = $node->hasChildNodes() ? $this->innerHtmlOfNode($node) : null;

        if ($slotFallback) {
            $this->addVariable('slot_default_fallback', $slotFallback);
            $variable = $this->builder->createVariableOutput(Slot::SLOT_PREFIX.Slot::SLOT_DEFAULT_NAME, 'slot_default_fallback');
        }
        else {
            $variable = $this->builder->createVariableOutput(Slot::SLOT_PREFIX.Slot::SLOT_DEFAULT_NAME);
        }

        $variableNode = $this->document->createTextNode($variable);


        $node->parentNode->insertBefore($variableNode, $node);
        $node->parentNode->removeChild($node);

    }
}
