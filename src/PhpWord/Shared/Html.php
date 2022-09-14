<?php
/**
 * This file is part of PHPWord - A pure PHP library for reading and writing
 * word processing documents.
 *
 * PHPWord is free software distributed under the terms of the GNU Lesser
 * General Public License version 3 as published by the Free Software Foundation.
 *
 * For the full copyright and license information, please read the LICENSE
 * file that was distributed with this source code. For the full list of
 * contributors, visit https://github.com/PHPOffice/PHPWord/contributors.
 *
 * @see         https://github.com/PHPOffice/PHPWord
 * @copyright   2010-2018 PHPWord contributors
 * @license     http://www.gnu.org/licenses/lgpl.txt LGPL version 3
 */

namespace PhpOffice\PhpWord\Shared;

use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Row;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\NumberFormat;
use PhpOffice\PhpWord\Style\Paragraph;

/**
 * Common Html functions
 *
 * @SuppressWarnings(PHPMD.UnusedPrivateMethod) For readWPNode
 */
class Html
{
    protected static $listIndex = 0;
    protected static $xpath;
    protected static $options;

    /**
     * Add HTML parts.
     *
     * Note: $stylesheet parameter is removed to avoid PHPMD error for unused parameter
     * Warning: Do not pass user-generated HTML here, as that would allow an attacker to read arbitrary
     * files or perform server-side request forgery by passing local file paths or URLs in <img>.
     *
     * @param \PhpOffice\PhpWord\Element\AbstractContainer $element Where the parts need to be added
     * @param string $html The code to parse
     * @param bool $fullHTML If it's a full HTML, no need to add 'body' tag
     * @param bool $preserveWhiteSpace If false, the whitespaces between nodes will be removed
     * @param array $options:
     *                + IMG_SRC_SEARCH: optional to speed up images loading from remote url when files can be found locally
     *                + IMG_SRC_REPLACE: optional to speed up images loading from remote url when files can be found locally
     */
    public static function addHtml($element, $html, $fullHTML = false, $preserveWhiteSpace = true, $options = null)
    {
        /*
         * @todo parse $stylesheet for default styles.  Should result in an array based on id, class and element,
         * which could be applied when such an element occurs in the parseNode function.
         */
        self::$options = $options;

        // Preprocess: remove all line ends, decode HTML entity,
        // fix ampersand and angle brackets and add body tag for HTML fragments
        $html = str_replace(array("\n", "\r"), '', $html);
        $html = str_replace(array('&lt;', '&gt;', '&amp;', '&quot;'), array('_lt_', '_gt_', '_amp_', '_quot_'), $html);
        $html = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
        $html = str_replace('&', '&amp;', $html);
        $html = str_replace(array('_lt_', '_gt_', '_amp_', '_quot_'), array('&lt;', '&gt;', '&amp;', '&quot;'), $html);

        if (false === $fullHTML) {
            $html = '<body>' . $html . '</body>';
        }

        // Load DOM
        if (\PHP_VERSION_ID < 80000) {
            $orignalLibEntityLoader = libxml_disable_entity_loader(true);
        }
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = $preserveWhiteSpace;
        $dom->loadXML($html);
        self::$xpath = new \DOMXPath($dom);
        $node = $dom->getElementsByTagName('body');

        self::parseNode($node->item(0), $element);
        if (\PHP_VERSION_ID < 80000) {
            libxml_disable_entity_loader($orignalLibEntityLoader);
        }
    }

    /**
     * parse Inline style of a node
     *
     * @param \DOMNode $node Node to check on attributes and to compile a style array
     * @param array $styles is supplied, the inline style attributes are added to the already existing style
     * @return array
     */
    protected static function parseInlineStyle($node, $styles = array())
    {
        if (XML_ELEMENT_NODE == $node->nodeType) {
            $attributes = $node->attributes; // get all the attributes(eg: id, class)

            foreach ($attributes as $attribute) {
                $val = $attribute->value;
                switch (strtolower($attribute->name)) {
                    case 'style':
                        $styles = self::parseStyle($attribute, $styles);
                        break;
                    case 'align':
                        $styles['alignment'] = self::mapAlign(trim($val));
                        break;
                    case 'lang':
                        $styles['lang'] = $val;
                        break;
                    case 'width':
                        // tables, cells
                        if (false !== strpos($val, '%')) {
                            // e.g. <table width="100%"> or <td width="50%">
                            $styles['width'] = (int) $val * 50;
                            $styles['unit'] = \PhpOffice\PhpWord\SimpleType\TblWidth::PERCENT;
                        } else {
                            // e.g. <table width="250> where "250" = 250px (always pixels)
                            $styles['width'] = Converter::pixelToTwip($val);
                            $styles['unit'] = \PhpOffice\PhpWord\SimpleType\TblWidth::TWIP;
                        }
                        break;
                    case 'cellspacing':
                        // tables e.g. <table cellspacing="2">,  where "2" = 2px (always pixels)
                        $val = (int) $val . 'px';
                        $styles['cellSpacing'] = Converter::cssToTwip($val);
                        break;
                    case 'bgcolor':
                        // tables, rows, cells e.g. <tr bgColor="#FF0000">
                        $styles['bgColor'] = trim($val, '# ');
                        break;
                    case 'valign':
                        // cells e.g. <td valign="middle">
                        if (preg_match('#(?:top|bottom|middle|baseline)#i', $val, $matches)) {
                            $styles['valign'] = self::mapAlignVertical($matches[0]);
                        }
                        break;
                }
            }
        }

        return $styles;
    }

    /**
     * Parse a node and add a corresponding element to the parent element.
     *
     * @param \DOMNode $node node to parse
     * @param \PhpOffice\PhpWord\Element\AbstractContainer $element object to add an element corresponding with the node
     * @param array $styles Array with all styles
     * @param array $data Array to transport data to a next level in the DOM tree, for example level of listitems
     */
    protected static function parseNode($node, $element, $styles = array(), $data = array())
    {
        // Populate styles array
        $styleTypes = array('font', 'paragraph', 'list', 'table', 'row', 'cell');
        foreach ($styleTypes as $styleType) {
            if (!isset($styles[$styleType])) {
                $styles[$styleType] = array();
            }
        }

        // Node mapping table
        $nodes = array(
                              // $method        $node   $element    $styles     $data   $argument1      $argument2
            'p'         => array('Paragraph',   $node,  $element,   $styles,    null,   null,           null),
            'h1'        => array('Heading',     null,   $element,   $styles,    null,   'Heading1',     null),
            'h2'        => array('Heading',     null,   $element,   $styles,    null,   'Heading2',     null),
            'h3'        => array('Heading',     null,   $element,   $styles,    null,   'Heading3',     null),
            'h4'        => array('Heading',     null,   $element,   $styles,    null,   'Heading4',     null),
            'h5'        => array('Heading',     null,   $element,   $styles,    null,   'Heading5',     null),
            'h6'        => array('Heading',     null,   $element,   $styles,    null,   'Heading6',     null),
            '#text'     => array('Text',        $node,  $element,   $styles,    null,   null,           null),
            'strong'    => array('Property',    null,   null,       $styles,    null,   'bold',         true),
            'b'         => array('Property',    null,   null,       $styles,    null,   'bold',         true),
            'em'        => array('Property',    null,   null,       $styles,    null,   'italic',       true),
            'i'         => array('Property',    null,   null,       $styles,    null,   'italic',       true),
            'u'         => array('Property',    null,   null,       $styles,    null,   'underline',    'single'),
            'sup'       => array('Property',    null,   null,       $styles,    null,   'superScript',  true),
            'sub'       => array('Property',    null,   null,       $styles,    null,   'subScript',    true),
            'span'      => array('Span',        $node,  null,       $styles,    null,   null,           null),
            'font'      => array('Span',        $node,  null,       $styles,    null,   null,           null),
            'table'     => array('Table',       $node,  $element,   $styles,    null,   null,           null),
            'tr'        => array('Row',         $node,  $element,   $styles,    null,   null,           null),
            'td'        => array('Cell',        $node,  $element,   $styles,    null,   null,           null),
            'th'        => array('Cell',        $node,  $element,   $styles,    null,   null,           null),
            'ul'        => array('List',        $node,  $element,   $styles,    $data,  null,           null),
            'ol'        => array('List',        $node,  $element,   $styles,    $data,  null,           null),
            'li'        => array('ListItem',    $node,  $element,   $styles,    $data,  null,           null),
            'img'       => array('Image',       $node,  $element,   $styles,    null,   null,           null),
            'br'        => array('LineBreak',   null,   $element,   $styles,    null,   null,           null),
            'a'         => array('Link',        $node,  $element,   $styles,    null,   null,           null),
            'input'     => array('Input',       $node,  $element,   $styles,    null,   null,           null),
            'hr'        => array('HorizRule',   $node,  $element,   $styles,    null,   null,           null),
        );

        $newElement = null;
        $keys = array('node', 'element', 'styles', 'data', 'argument1', 'argument2');

        if (isset($nodes[$node->nodeName])) {
            // Execute method based on node mapping table and return $newElement or null
            // Arguments are passed by reference
            $arguments = array();
            $args = array();
            list($method, $args[0], $args[1], $args[2], $args[3], $args[4], $args[5]) = $nodes[$node->nodeName];
            for ($i = 0; $i <= 5; $i++) {
                if ($args[$i] !== null) {
                    $arguments[$keys[$i]] = &$args[$i];
                }
            }
            $method = "parse{$method}";
            $newElement = call_user_func_array(array('PhpOffice\PhpWord\Shared\Html', $method), array_values($arguments));

            // Retrieve back variables from arguments
            foreach ($keys as $key) {
                if (array_key_exists($key, $arguments)) {
                    $$key = $arguments[$key];
                }
            }
        }

        if ($newElement === null) {
            $newElement = $element;
        }

        static::parseChildNodes($node, $newElement, $styles, $data);
    }

    /**
     * Parse child nodes.
     *
     * @param \DOMNode $node
     * @param \PhpOffice\PhpWord\Element\AbstractContainer $element
     * @param array $styles
     * @param array $data
     */
    protected static function parseChildNodes($node, $element, $styles, $data)
    {
        if ('li' != $node->nodeName) {
            $cNodes = $node->childNodes;
            if (!empty($cNodes)) {
                foreach ($cNodes as $cNode) {
                    if ($element instanceof AbstractContainer || $element instanceof Table || $element instanceof Row) {
                        self::parseNode($cNode, $element, $styles, $data);
                    }
                }
            }
        }
    }

    /**
     * Parse paragraph node
     *
     * @param \DOMNode $node
     * @param \PhpOffice\PhpWord\Element\AbstractContainer $element
     * @param array &$styles
     * @return \PhpOffice\PhpWord\Element\TextRun|\PhpOffice\PhpWord\Element\PageBreak
     */
    protected static function parseParagraph($node, $element, &$styles)
    {
        $styles['paragraph'] = self::recursiveParseStylesInHierarchy($node, $styles['paragraph']);
        if (isset($styles['paragraph']['isPageBreak']) && $styles['paragraph']['isPageBreak']) {
            return $element->addPageBreak();
        }

        return $element->addTextRun($styles['paragraph']);
    }

    /**
     * Parse input node
     *
     * @param \DOMNode $node
     * @param \PhpOffice\PhpWord\Element\AbstractContainer $element
     * @param array &$styles
     */
    protected static function parseInput($node, $element, &$styles)
    {
        $attributes = $node->attributes;
        if (null === $attributes->getNamedItem('type')) {
            return;
        }

        $inputType = $attributes->getNamedItem('type')->value;
        switch ($inputType) {
            case 'checkbox':
                $checked = ($checked = $attributes->getNamedItem('checked')) && $checked->value === 'true' ? true : false;
                $textrun = $element->addTextRun($styles['paragraph']);
                $textrun->addFormField('checkbox')->setValue($checked);
                break;
        }
    }

    /**
     * Parse heading node
     *
     * @param \PhpOffice\PhpWord\Element\AbstractContainer $element
     * @param array &$styles
     * @param string $argument1 Name of heading style
     * @return \PhpOffice\PhpWord\Element\TextRun
     *
     * @todo Think of a clever way of defining header styles, now it is only based on the assumption, that
     * Heading1 - Heading6 are already defined somewhere
     */
    protected static function parseHeading($element, &$styles, $argument1)
    {
        $styles['paragraph'] = $argument1;
        $newElement = $element->addTextRun($styles['paragraph']);

        return $newElement;
    }

    /**
     * Parse text node
     *
     * @param \DOMNode $node
     * @param \PhpOffice\PhpWord\Element\AbstractContainer $element
     * @param array &$styles
     */
    protected static function parseText($node, $element, &$styles)
    {
        $styles['font'] = self::recursiveParseStylesInHierarchy($node, $styles['font']);

        //alignment applies on paragraph, not on font. Let's copy it there
        if (isset($styles['font']['alignment']) && is_array($styles['paragraph'])) {
            $styles['paragraph']['alignment'] = $styles['font']['alignment'];
        }

        if (is_callable(array($element, 'addText'))) {
            $element->addText($node->nodeValue, $styles['font'], $styles['paragraph']);
        }
    }

    /**
     * Parse property node
     *
     * @param array &$styles
     * @param string $argument1 Style name
     * @param string $argument2 Style value
     */
    protected static function parseProperty(&$styles, $argument1, $argument2)
    {
        $styles['font'][$argument1] = $argument2;
    }

    /**
     * Parse span node
     *
     * @param \DOMNode $node
     * @param array &$styles
     */
    protected static function parseSpan($node, &$styles)
    {
        self::parseInlineStyle($node, $styles['font']);
    }

    /**
     * Parse table node
     *
     * @param \DOMNode $node
     * @param \PhpOffice\PhpWord\Element\AbstractContainer $element
     * @param array &$styles
     * @return Table $element
     *
     * @todo As soon as TableItem, RowItem and CellItem support relative width and height
     */
    protected static function parseTable($node, $element, &$styles)
    {
        $elementStyles = self::parseInlineStyle($node, $styles['table']);

        $newElement = $element->addTable($elementStyles);

        $attributes = $node->attributes;
        if ($attributes->getNamedItem('border') !== null) {
            $border = (int) $attributes->getNamedItem('border')->value;
            $newElement->getStyle()->setBorderSize(Converter::pixelToTwip($border));
        }

        return $newElement;
    }

    /**
     * Parse a table row
     *
     * @param \DOMNode $node
     * @param \PhpOffice\PhpWord\Element\Table $element
     * @param array &$styles
     * @return Row $element
     */
    protected static function parseRow($node, $element, &$styles)
    {
        $rowStyles = self::parseInlineStyle($node, $styles['row']);
        if ($node->parentNode->nodeName == 'thead') {
            $rowStyles['tblHeader'] = true;
        }

        return $element->addRow(null, $rowStyles);
    }

    /**
     * Parse table cell
     *
     * @param \DOMNode $node
     * @param \PhpOffice\PhpWord\Element\Table $element
     * @param array &$styles
     * @return \PhpOffice\PhpWord\Element\Cell|\PhpOffice\PhpWord\Element\TextRun $element
     */
    protected static function parseCell($node, $element, &$styles)
    {
        $cellStyles = self::recursiveParseStylesInHierarchy($node, $styles['cell']);

        $colspan = $node->getAttribute('colspan');
        if (!empty($colspan)) {
            $cellStyles['gridSpan'] = $colspan - 0;
        }

        // set cell width to control column widths
        $width = isset($cellStyles['width']) ? $cellStyles['width'] : null;
        unset($cellStyles['width']); // would not apply
        $cell = $element->addCell($width, $cellStyles);

        if (self::shouldAddTextRun($node)) {
            return $cell->addTextRun(self::filterOutNonInheritedStyles(self::parseInlineStyle($node, $styles['paragraph'])));
        }

        return $cell;
    }

    /**
     * Checks if $node contains an HTML element that cannot be added to TextRun
     *
     * @param \DOMNode $node
     * @return bool Returns true if the node contains an HTML element that cannot be added to TextRun
     */
    protected static function shouldAddTextRun(\DOMNode $node)
    {
        $containsBlockElement = self::$xpath->query('.//table|./p|./ul|./ol|./h1|./h2|./h3|./h4|./h5|./h6', $node)->length > 0;
        if ($containsBlockElement) {
            return false;
        }

        return true;
    }

    /**
     * Recursively parses styles on parent nodes
     * TODO if too slow, add caching of parent nodes, !! everything is static here so watch out for concurrency !!
     *
     * @param \DOMNode $node
     * @param array &$styles
     */
    protected static function recursiveParseStylesInHierarchy(\DOMNode $node, array $style)
    {
        $parentStyle = array();
        if ($node->parentNode != null && XML_ELEMENT_NODE == $node->parentNode->nodeType) {
            $parentStyle = self::recursiveParseStylesInHierarchy($node->parentNode, array());
        }
        if ($node->nodeName === '#text') {
            $parentStyle = array_merge($parentStyle, $style);
        } else {
            $parentStyle = self::filterOutNonInheritedStyles($parentStyle);
        }
        $style = self::parseInlineStyle($node, $parentStyle);

        return $style;
    }

    /**
     * Removes non-inherited styles from array
     *
     * @param array &$styles
     */
    protected static function filterOutNonInheritedStyles(array $styles)
    {
        $nonInheritedStyles = array(
            'borderSize',
            'borderTopSize',
            'borderRightSize',
            'borderBottomSize',
            'borderLeftSize',
            'borderColor',
            'borderTopColor',
            'borderRightColor',
            'borderBottomColor',
            'borderLeftColor',
            'borderStyle',
            'spaceAfter',
            'spaceBefore',
            'underline',
            'strikethrough',
            'hidden',
        );

        $styles = array_diff_key($styles, array_flip($nonInheritedStyles));

        return $styles;
    }

    /**
     * Parse list node
     *
     * @param \DOMNode $node
     * @param \PhpOffice\PhpWord\Element\AbstractContainer $element
     * @param array &$styles
     * @param array &$data
     */
    protected static function parseList($node, $element, &$styles, &$data)
    {
        $isOrderedList = $node->nodeName === 'ol';
        if (isset($data['listdepth'])) {
            $data['listdepth']++;
        } else {
            $data['listdepth'] = 0;
            $styles['list'] = 'listStyle_' . self::$listIndex++;
            $style = $element->getPhpWord()->addNumberingStyle($styles['list'], self::getListStyle($isOrderedList));

            // extract attributes start & type e.g. <ol type="A" start="3">
            $start = 0;
            $type = '';
            foreach ($node->attributes as $attribute) {
                switch ($attribute->name) {
                    case 'start':
                        $start = (int) $attribute->value;
                        break;
                    case 'type':
                        $type = $attribute->value;
                        break;
                }
            }

            $levels = $style->getLevels();
            /** @var \PhpOffice\PhpWord\Style\NumberingLevel */
            $level = $levels[0];
            if ($start > 0) {
                $level->setStart($start);
            }
            $type = $type ? self::mapListType($type) : null;
            if ($type) {
                $level->setFormat($type);
            }
        }
        if ($node->parentNode->nodeName === 'li') {
            return $element->getParent();
        }
    }

    /**
     * @param bool $isOrderedList
     * @return array
     */
    protected static function getListStyle($isOrderedList)
    {
        if ($isOrderedList) {
            return array(
                'type'   => 'multilevel',
                'levels' => array(
                    array('format' => NumberFormat::DECIMAL,      'text' => '%1.', 'alignment' => 'left',  'tabPos' => 720,  'left' => 720,  'hanging' => 360),
                    array('format' => NumberFormat::LOWER_LETTER, 'text' => '%2.', 'alignment' => 'left',  'tabPos' => 1440, 'left' => 1440, 'hanging' => 360),
                    array('format' => NumberFormat::LOWER_ROMAN,  'text' => '%3.', 'alignment' => 'right', 'tabPos' => 2160, 'left' => 2160, 'hanging' => 180),
                    array('format' => NumberFormat::DECIMAL,      'text' => '%4.', 'alignment' => 'left',  'tabPos' => 2880, 'left' => 2880, 'hanging' => 360),
                    array('format' => NumberFormat::LOWER_LETTER, 'text' => '%5.', 'alignment' => 'left',  'tabPos' => 3600, 'left' => 3600, 'hanging' => 360),
                    array('format' => NumberFormat::LOWER_ROMAN,  'text' => '%6.', 'alignment' => 'right', 'tabPos' => 4320, 'left' => 4320, 'hanging' => 180),
                    array('format' => NumberFormat::DECIMAL,      'text' => '%7.', 'alignment' => 'left',  'tabPos' => 5040, 'left' => 5040, 'hanging' => 360),
                    array('format' => NumberFormat::LOWER_LETTER, 'text' => '%8.', 'alignment' => 'left',  'tabPos' => 5760, 'left' => 5760, 'hanging' => 360),
                    array('format' => NumberFormat::LOWER_ROMAN,  'text' => '%9.', 'alignment' => 'right', 'tabPos' => 6480, 'left' => 6480, 'hanging' => 180),
                ),
            );
        }

        return array(
            'type'   => 'hybridMultilevel',
            'levels' => array(
                array('format' => NumberFormat::BULLET, 'text' => '', 'alignment' => 'left', 'tabPos' => 720,  'left' => 720,  'hanging' => 360, 'font' => 'Symbol',      'hint' => 'default'),
                array('format' => NumberFormat::BULLET, 'text' => 'o',  'alignment' => 'left', 'tabPos' => 1440, 'left' => 1440, 'hanging' => 360, 'font' => 'Courier New', 'hint' => 'default'),
                array('format' => NumberFormat::BULLET, 'text' => '', 'alignment' => 'left', 'tabPos' => 2160, 'left' => 2160, 'hanging' => 360, 'font' => 'Wingdings',   'hint' => 'default'),
                array('format' => NumberFormat::BULLET, 'text' => '', 'alignment' => 'left', 'tabPos' => 2880, 'left' => 2880, 'hanging' => 360, 'font' => 'Symbol',      'hint' => 'default'),
                array('format' => NumberFormat::BULLET, 'text' => 'o',  'alignment' => 'left', 'tabPos' => 3600, 'left' => 3600, 'hanging' => 360, 'font' => 'Courier New', 'hint' => 'default'),
                array('format' => NumberFormat::BULLET, 'text' => '', 'alignment' => 'left', 'tabPos' => 4320, 'left' => 4320, 'hanging' => 360, 'font' => 'Wingdings',   'hint' => 'default'),
                array('format' => NumberFormat::BULLET, 'text' => '', 'alignment' => 'left', 'tabPos' => 5040, 'left' => 5040, 'hanging' => 360, 'font' => 'Symbol',      'hint' => 'default'),
                array('format' => NumberFormat::BULLET, 'text' => 'o',  'alignment' => 'left', 'tabPos' => 5760, 'left' => 5760, 'hanging' => 360, 'font' => 'Courier New', 'hint' => 'default'),
                array('format' => NumberFormat::BULLET, 'text' => '', 'alignment' => 'left', 'tabPos' => 6480, 'left' => 6480, 'hanging' => 360, 'font' => 'Wingdings',   'hint' => 'default'),
            ),
        );
    }

    /**
     * Parse list item node
     *
     * @param \DOMNode $node
     * @param \PhpOffice\PhpWord\Element\AbstractContainer $element
     * @param array &$styles
     * @param array $data
     *
     * @todo This function is almost the same like `parseChildNodes`. Merged?
     * @todo As soon as ListItem inherits from AbstractContainer or TextRun delete parsing part of childNodes
     */
    protected static function parseListItem($node, $element, &$styles, $data)
    {
        $cNodes = $node->childNodes;
        if (!empty($cNodes)) {
            $listRun = $element->addListItemRun($data['listdepth'], $styles['list'], $styles['paragraph']);
            foreach ($cNodes as $cNode) {
                self::parseNode($cNode, $listRun, $styles, $data);
            }
        }
    }

    /**
     * Parse style
     *
     * @param \DOMAttr $attribute
     * @param array $styles
     * @return array
     */
    protected static function parseStyle($attribute, $styles)
    {
        $properties = explode(';', trim($attribute->value, " \t\n\r\0\x0B;"));

        foreach ($properties as $property) {
            list($cKey, $cValue) = array_pad(explode(':', $property, 2), 2, null);
            $cValue = trim($cValue);
            $cKey = strtolower(trim($cKey));
            switch ($cKey) {
                case 'text-decoration':
                    switch ($cValue) {
                        case 'underline':
                            $styles['underline'] = 'single';
                            break;
                        case 'line-through':
                            $styles['strikethrough'] = true;
                            break;
                    }
                    break;
                case 'text-align':
                    $styles['alignment'] = self::mapAlign($cValue);
                    break;
                case 'display':
                    $styles['hidden'] = $cValue === 'none' || $cValue === 'hidden';
                    break;
                case 'direction':
                    $styles['rtl'] = $cValue === 'rtl';
                    break;
                case 'font-size':
                    $styles['size'] = Converter::cssToPoint($cValue);
                    break;
                case 'font-family':
                    $cValue = array_map('trim', explode(',', $cValue));
                    $styles['name'] = ucwords($cValue[0]);
                    break;
                case 'color':
                    $styles['color'] = trim($cValue, '#');
                    break;
                case 'background-color':
                    $styles['bgColor'] = trim($cValue, '#');
                    break;
                case 'line-height':
                    $matches = array();
                    if ($cValue === 'normal') {
                        $spacingLineRule = \PhpOffice\PhpWord\SimpleType\LineSpacingRule::AUTO;
                        $spacing = 0;
                    } elseif (preg_match('/([0-9]+\.?[0-9]*[a-z]+)/', $cValue, $matches)) {
                        //matches number with a unit, e.g. 12px, 15pt, 20mm, ...
                        $spacingLineRule = \PhpOffice\PhpWord\SimpleType\LineSpacingRule::EXACT;
                        $spacing = Converter::cssToTwip($matches[1]);
                    } elseif (preg_match('/([0-9]+)%/', $cValue, $matches)) {
                        //matches percentages
                        $spacingLineRule = \PhpOffice\PhpWord\SimpleType\LineSpacingRule::AUTO;
                        //we are subtracting 1 line height because the Spacing writer is adding one line
                        $spacing = ((((int) $matches[1]) / 100) * Paragraph::LINE_HEIGHT) - Paragraph::LINE_HEIGHT;
                    } else {
                        //any other, wich is a multiplier. E.g. 1.2
                        $spacingLineRule = \PhpOffice\PhpWord\SimpleType\LineSpacingRule::AUTO;
                        //we are subtracting 1 line height because the Spacing writer is adding one line
                        $spacing = ($cValue * Paragraph::LINE_HEIGHT) - Paragraph::LINE_HEIGHT;
                    }
                    $styles['spacingLineRule'] = $spacingLineRule;
                    $styles['line-spacing'] = $spacing;
                    break;
                case 'letter-spacing':
                    $styles['letter-spacing'] = Converter::cssToTwip($cValue);
                    break;
                case 'text-indent':
                    $styles['indentation']['firstLine'] = Converter::cssToTwip($cValue);
                    break;
                case 'font-weight':
                    $tValue = false;
                    if (preg_match('#bold#', $cValue)) {
                        $tValue = true; // also match bolder
                    }
                    $styles['bold'] = $tValue;
                    break;
                case 'font-style':
                    $tValue = false;
                    if (preg_match('#(?:italic|oblique)#', $cValue)) {
                        $tValue = true;
                    }
                    $styles['italic'] = $tValue;
                    break;
                case 'margin':
                    $cValue = Converter::cssToTwip($cValue);
                    $styles['spaceBefore'] = $cValue;
                    $styles['spaceAfter'] = $cValue;
                    break;
                case 'margin-top':
                    // BC change: up to ver. 0.17.0 incorrectly converted to points - Converter::cssToPoint($cValue)
                    $styles['spaceBefore'] = Converter::cssToTwip($cValue);
                    break;
                case 'margin-bottom':
                    // BC change: up to ver. 0.17.0 incorrectly converted to points - Converter::cssToPoint($cValue)
                    $styles['spaceAfter'] = Converter::cssToTwip($cValue);
                    break;
                case 'border-color':
                    self::mapBorderColor($styles, $cValue);
                    break;
                case 'border-width':
                    $styles['borderSize'] = Converter::cssToPoint($cValue);
                    break;
                case 'border-style':
                    $styles['borderStyle'] = self::mapBorderStyle($cValue);
                    break;
                case 'width':
                    if (preg_match('/([0-9]+[a-z]+)/', $cValue, $matches)) {
                        $styles['width'] = Converter::cssToTwip($matches[1]);
                        $styles['unit'] = \PhpOffice\PhpWord\SimpleType\TblWidth::TWIP;
                    } elseif (preg_match('/([0-9]+)%/', $cValue, $matches)) {
                        $styles['width'] = $matches[1] * 50;
                        $styles['unit'] = \PhpOffice\PhpWord\SimpleType\TblWidth::PERCENT;
                    } elseif (preg_match('/([0-9]+)/', $cValue, $matches)) {
                        $styles['width'] = $matches[1];
                        $styles['unit'] = \PhpOffice\PhpWord\SimpleType\TblWidth::AUTO;
                    }
                    break;
                case 'border':
                case 'border-top':
                case 'border-bottom':
                case 'border-right':
                case 'border-left':
                    // must have exact order [width color style], e.g. "1px #0011CC solid" or "2pt green solid"
                    // Word does not accept shortened hex colors e.g. #CCC, only full e.g. #CCCCCC
                    if (preg_match('/([0-9]+[^0-9]*)\s+(\#[a-fA-F0-9]+|[a-zA-Z]+)\s+([a-z]+)/', $cValue, $matches)) {
                        if (false !== strpos($cKey, '-')) {
                            $tmp = explode('-', $cKey);
                            $which = $tmp[1];
                            $which = ucfirst($which); // e.g. bottom -> Bottom
                        } else {
                            $which = '';
                        }
                        // Note - border width normalization:
                        // Width of border in Word is calculated differently than HTML borders, usually showing up too bold.
                        // Smallest 1px (or 1pt) appears in Word like 2-3px/pt in HTML once converted to twips.
                        // Therefore we need to normalize converted twip value to cca 1/2 of value.
                        // This may be adjusted, if better ratio or formula found.
                        // BC change: up to ver. 0.17.0 was $size converted to points - Converter::cssToPoint($size)
                        $size = Converter::cssToTwip($matches[1]);
                        $size = (int) ($size / 2);
                        // valid variants may be e.g. borderSize, borderTopSize, borderLeftColor, etc ..
                        $styles["border{$which}Size"] = $size; // twips
                        $styles["border{$which}Color"] = trim($matches[2], '#');
                        $styles["border{$which}Style"] = self::mapBorderStyle($matches[3]);
                    }
                    break;
                case 'vertical-align':
                    // https://developer.mozilla.org/en-US/docs/Web/CSS/vertical-align
                    if (preg_match('#(?:top|bottom|middle|sub|baseline)#i', $cValue, $matches)) {
                        $styles['valign'] = self::mapAlignVertical($matches[0]);
                    }
                    break;
                case 'page-break-after':
                    if ($cValue == 'always') {
                        $styles['isPageBreak'] = true;
                    }
                    break;
            }
        }

        return $styles;
    }

    /**
     * Parse image node
     *
     * @param \DOMNode $node
     * @param \PhpOffice\PhpWord\Element\AbstractContainer $element
     *
     * @return \PhpOffice\PhpWord\Element\Image
     **/
    protected static function parseImage($node, $element)
    {
        $style = array();
        $src = null;
        foreach ($node->attributes as $attribute) {
            switch ($attribute->name) {
                case 'src':
                    $src = $attribute->value;
                    break;
                case 'width':
                    $width = $attribute->value;
                    $style['width'] = $width;
                    $style['unit'] = \PhpOffice\PhpWord\Style\Image::UNIT_PX;
                    break;
                case 'height':
                    $height = $attribute->value;
                    $style['height'] = $height;
                    $style['unit'] = \PhpOffice\PhpWord\Style\Image::UNIT_PX;
                    break;
                case 'style':
                    $styleattr = explode(';', $attribute->value);
                    foreach ($styleattr as $attr) {
                        if (strpos($attr, ':')) {
                            list($k, $v) = explode(':', $attr);
                            switch ($k) {
                                case 'float':
                                    if (trim($v) == 'right') {
                                        $style['hPos'] = \PhpOffice\PhpWord\Style\Image::POS_RIGHT;
                                        $style['hPosRelTo'] = \PhpOffice\PhpWord\Style\Image::POS_RELTO_MARGIN; // inner section area
                                        $style['pos'] = \PhpOffice\PhpWord\Style\Image::POS_RELATIVE;
                                        $style['wrap'] = \PhpOffice\PhpWord\Style\Image::WRAP_TIGHT;
                                        $style['overlap'] = true;
                                    }
                                    if (trim($v) == 'left') {
                                        $style['hPos'] = \PhpOffice\PhpWord\Style\Image::POS_LEFT;
                                        $style['hPosRelTo'] = \PhpOffice\PhpWord\Style\Image::POS_RELTO_MARGIN; // inner section area
                                        $style['pos'] = \PhpOffice\PhpWord\Style\Image::POS_RELATIVE;
                                        $style['wrap'] = \PhpOffice\PhpWord\Style\Image::WRAP_TIGHT;
                                        $style['overlap'] = true;
                                    }
                                    break;
                            }
                        }
                    }
                    break;
            }
        }
        $originSrc = $src;
        if (strpos($src, 'data:image') !== false) {
            $tmpDir = Settings::getTempDir() . '/';

            $match = array();
            preg_match('/data:image\/(\w+);base64,(.+)/', $src, $match);

            $src = $imgFile = $tmpDir . uniqid() . '.' . $match[1];

            $ifp = fopen($imgFile, 'wb');

            if ($ifp !== false) {
                fwrite($ifp, base64_decode($match[2]));
                fclose($ifp);
            }
        }
        $src = urldecode($src);

        if (!is_file($src)
            && !is_null(self::$options)
            && isset(self::$options['IMG_SRC_SEARCH'])
            && isset(self::$options['IMG_SRC_REPLACE'])) {
            $src = str_replace(self::$options['IMG_SRC_SEARCH'], self::$options['IMG_SRC_REPLACE'], $src);
        }

        if (!is_file($src)) {
            if ($imgBlob = @file_get_contents($src)) {
                $tmpDir = Settings::getTempDir() . '/';
                $match = array();
                preg_match('/.+\.(\w+)$/', $src, $match);
                $src = $tmpDir . uniqid() . '.' . $match[1];

                $ifp = fopen($src, 'wb');

                if ($ifp !== false) {
                    fwrite($ifp, $imgBlob);
                    fclose($ifp);
                }
            }
        }

        if (is_file($src)) {
            $newElement = $element->addImage($src, $style);
        } else {
            throw new \Exception("Could not load image $originSrc");
        }

        return $newElement;
    }

    /**
     * Transforms a CSS border style into a word border style
     *
     * @param string $cssBorderStyle
     * @return null|string
     */
    protected static function mapBorderStyle($cssBorderStyle)
    {
        switch ($cssBorderStyle) {
            case 'none':
            case 'dashed':
            case 'dotted':
            case 'double':
                return $cssBorderStyle;
            default:
                return 'single';
        }
    }

    protected static function mapBorderColor(&$styles, $cssBorderColor)
    {
        $numColors = substr_count($cssBorderColor, '#');
        if ($numColors === 1) {
            $styles['borderColor'] = trim($cssBorderColor, '#');
        } elseif ($numColors > 1) {
            $colors = explode(' ', $cssBorderColor);
            $borders = array('borderTopColor', 'borderRightColor', 'borderBottomColor', 'borderLeftColor');
            for ($i = 0; $i < min(4, $numColors, count($colors)); $i++) {
                $styles[$borders[$i]] = trim($colors[$i], '#');
            }
        }
    }

    /**
     * Transforms a HTML/CSS alignment into a \PhpOffice\PhpWord\SimpleType\Jc
     *
     * @param string $cssAlignment
     * @return string|null
     */
    protected static function mapAlign($cssAlignment)
    {
        switch ($cssAlignment) {
            case 'right':
                return Jc::END;
            case 'center':
                return Jc::CENTER;
            case 'justify':
                return Jc::BOTH;
            default:
                return Jc::START;
        }
    }

    /**
     * Transforms a HTML/CSS vertical alignment
     *
     * @param string $alignment
     * @return string|null
     */
    protected static function mapAlignVertical($alignment)
    {
        $alignment = strtolower($alignment);
        switch ($alignment) {
            case 'top':
            case 'baseline':
            case 'bottom':
                return $alignment;
            case 'middle':
                return 'center';
            case 'sub':
                return 'bottom';
            case 'text-top':
            case 'baseline':
                return 'top';
            default:
                // @discuss - which one should apply:
                // - Word uses default vert. alignment: top
                // - all browsers use default vert. alignment: middle
                // Returning empty string means attribute wont be set so use Word default (top).
                return '';
        }
    }

    /**
     * Map list style for ordered list
     *
     * @param string $cssListType
     */
    protected static function mapListType($cssListType)
    {
        switch ($cssListType) {
            case 'a':
                return NumberFormat::LOWER_LETTER; // a, b, c, ..
            case 'A':
                return NumberFormat::UPPER_LETTER; // A, B, C, ..
            case 'i':
                return NumberFormat::LOWER_ROMAN; // i, ii, iii, iv, ..
            case 'I':
                return NumberFormat::UPPER_ROMAN; // I, II, III, IV, ..
            case '1':
            default:
                return NumberFormat::DECIMAL; // 1, 2, 3, ..
        }
    }

    /**
     * Parse line break
     *
     * @param \PhpOffice\PhpWord\Element\AbstractContainer $element
     */
    protected static function parseLineBreak($element)
    {
        $element->addTextBreak();
    }

    /**
     * Parse link node
     *
     * @param \DOMNode $node
     * @param \PhpOffice\PhpWord\Element\AbstractContainer $element
     * @param array $styles
     */
    protected static function parseLink($node, $element, &$styles)
    {
        $target = null;
        foreach ($node->attributes as $attribute) {
            switch ($attribute->name) {
                case 'href':
                    $target = $attribute->value;
                    break;
            }
        }
        $styles['font'] = self::parseInlineStyle($node, $styles['font']);

        if (strpos($target, '#') === 0) {
            return $element->addLink(substr($target, 1), $node->textContent, $styles['font'], $styles['paragraph'], true);
        }

        return $element->addLink($target, $node->textContent, $styles['font'], $styles['paragraph']);
    }

    /**
     * Render horizontal rule
     * Note: Word rule is not the same as HTML's <hr> since it does not support width and thus neither alignment
     *
     * @param \DOMNode $node
     * @param \PhpOffice\PhpWord\Element\AbstractContainer $element
     */
    protected static function parseHorizRule($node, $element)
    {
        $styles = self::parseInlineStyle($node);

        // <hr> is implemented as an empty paragraph - extending 100% inside the section
        // Some properties may be controlled, e.g. <hr style="border-bottom: 3px #DDDDDD solid; margin-bottom: 0;">

        $fontStyle = $styles + array('size' => 3);

        $paragraphStyle = $styles + array(
            'lineHeight'        => 0.25, // multiply default line height - e.g. 1, 1.5 etc
            'spacing'           => 0, // twip
            'spaceBefore'       => 120, // twip, 240/2 (default line height)
            'spaceAfter'        => 120, // twip
            'borderBottomSize'  => empty($styles['line-height']) ? 1 : $styles['line-height'],
            'borderBottomColor' => empty($styles['color']) ? '000000' : $styles['color'],
            'borderBottomStyle' => 'single', // same as "solid"
        );

        $element->addText('', $fontStyle, $paragraphStyle);

        // Notes: <hr/> cannot be:
        // - table - throws error "cannot be inside textruns", e.g. lists
        // - line - that is a shape, has different behaviour
        // - repeated text, e.g. underline "_", because of unpredictable line wrapping
    }
}
