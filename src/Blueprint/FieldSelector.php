<?php

namespace DataHelm\Crawler\Blueprint;

/**
 * Describes how to extract one field from an element.
 *
 * @property-read string      $name      Output key (e.g. "title", "price").
 * @property-read string      $css       CSS selector relative to the context node ('' = the node itself).
 *                                        For "xpath" it holds the XPath expression; for "json" it holds
 *                                        the JSON dot-path (e.g. "lotDetails.year").
 * @property-read string      $type      Selector type: "css" (default), "xpath", "json", or
 *                                        "markdown" (locate with CSS, render the matched
 *                                        element's content as clean Markdown).
 * @property-read string|null $attribute Attribute to read (null => text content).
 * @property-read string|null $regex     Optional capture pattern applied to the raw value.
 * @property-read bool        $multiple  Extract every match as an array (e.g. an image gallery).
 * @property-read string|null $label     If set, the value is found by this label (e.g. "1ª Praça")
 *                                        instead of by CSS/XPath — used for "label: value" data.
 */
final class FieldSelector
{
    public function __construct(
        public readonly string $name,
        public readonly string $css = '',
        public readonly ?string $attribute = null,
        public readonly ?string $regex = null,
        public readonly bool $multiple = false,
        public readonly ?string $label = null,
        public readonly string $type = 'css',
    ) {
    }

    public function renamed(string $name): self
    {
        return new self($name, $this->css, $this->attribute, $this->regex, $this->multiple, $this->label, $this->type);
    }

    public static function fromArray(array $data): self
    {
        $type = (string) ($data['type'] ?? 'css');

        return new self(
            name:      (string) $data['name'],
            css:       (string) ($data['css'] ?? ''),
            attribute: $data['attribute'] ?? null,
            regex:     $data['regex'] ?? null,
            multiple:  (bool) ($data['multiple'] ?? false),
            label:     $data['label'] ?? null,
            type:      in_array($type, ['css', 'xpath', 'json', 'markdown'], true) ? $type : 'css',
        );
    }

    /**
     * @return array{name:string,css:string,type:string,attribute:string|null,regex:string|null,multiple:bool,label:string|null}
     */
    public function toArray(): array
    {
        return [
            'name'      => $this->name,
            'css'       => $this->css,
            'type'      => $this->type,
            'attribute' => $this->attribute,
            'regex'     => $this->regex,
            'multiple'  => $this->multiple,
            'label'     => $this->label,
        ];
    }
}
