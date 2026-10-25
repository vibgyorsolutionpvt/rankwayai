<?php

namespace App\Services\Channels;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

class EmailHtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'a', 'blockquote', 'br', 'code', 'em', 'h2', 'h3', 'hr', 'img', 'li',
        'ol', 'p', 'pre', 's', 'span', 'strong', 'u', 'ul',
    ];

    private const DROP_CONTENT_TAGS = [
        'iframe', 'object', 'script', 'style', 'svg', 'template',
    ];

    public function sanitize(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        if (! preg_match('/<\/?(?:a|blockquote|br|code|em|h[23]|hr|img|li|ol|p|pre|s|span|strong|u|ul)\b/i', $html)) {
            return '<p>'.nl2br(e($html), false).'</p>';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrorMode = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="email-content">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorMode);

        $root = (new DOMXPath($document))->query('//*[@id="email-content"]')->item(0);
        if (! $root) {
            return '';
        }

        $this->sanitizeChildren($root);

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child);
        }

        return $output;
    }

    private function sanitizeChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($child->nodeType === XML_COMMENT_NODE) {
                $parent->removeChild($child);

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP_CONTENT_TAGS, true)) {
                $parent->removeChild($child);

                continue;
            }

            $this->sanitizeChildren($child);

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                while ($child->firstChild) {
                    $parent->insertBefore($child->firstChild, $child);
                }
                $parent->removeChild($child);

                continue;
            }

            foreach (iterator_to_array($child->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                $value = trim($attribute->value);

                if ($tag === 'a' && $name === 'href' && $this->hasAllowedScheme($value, ['http', 'https', 'mailto'])) {
                    continue;
                }

                if ($tag === 'img' && in_array($name, ['alt', 'title'], true)) {
                    continue;
                }

                if ($tag === 'img' && $name === 'src' && $this->hasAllowedScheme($value, ['http', 'https'])) {
                    continue;
                }

                if (in_array($tag, ['p', 'h2', 'h3'], true)
                    && $name === 'style'
                    && preg_match('/^text-align:\s*(left|center|right)\s*;?$/i', $value, $matches)) {
                    $child->setAttribute('style', 'text-align: '.strtolower($matches[1]));

                    continue;
                }

                $child->removeAttribute($attribute->name);
            }

            if ($tag === 'a' && $child->hasAttribute('href')) {
                $child->setAttribute('rel', 'noopener noreferrer');
            }
        }
    }

    /**
     * @param  list<string>  $schemes
     */
    private function hasAllowedScheme(string $url, array $schemes): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return $scheme !== '' && in_array($scheme, $schemes, true);
    }
}
