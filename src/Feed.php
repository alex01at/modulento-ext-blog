<?php

declare(strict_types=1);

namespace Modulento\Blog;

use XMLWriter;

/**
 * The Atom feed (RFC 4287). Written with XMLWriter rather than a template:
 * every value is escaped by the writer, and a feed is not something a
 * theme restyles.
 */
final class Feed
{
    /**
     * @param array{title: string, locale: string, self: string, home: string, updated: string} $feed
     *        addresses absolute, "updated" as "Y-m-d\TH:i:s\Z"
     * @param array<int, array{title: string, url: string, locale: string, published: string, updated: string,
     *        summary: ?string, body: string, author: ?string, category: ?string}> $entries
     */
    public static function atom(array $feed, array $entries): string
    {
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('feed');
        $xml->writeAttribute('xmlns', 'http://www.w3.org/2005/Atom');
        $xml->writeAttribute('xml:lang', $feed['locale']);
        $xml->writeElement('title', self::text($feed['title']));
        $xml->writeElement('id', $feed['self']);
        $xml->writeElement('updated', $feed['updated']);
        self::link($xml, 'self', $feed['self'], 'application/atom+xml');
        self::link($xml, 'alternate', $feed['home'], 'text/html');
        // A feed whose entries name no author has to name one itself.
        $xml->startElement('author');
        $xml->writeElement('name', self::text($feed['title']));
        $xml->endElement();

        foreach ($entries as $entry) {
            $xml->startElement('entry');
            $xml->writeAttribute('xml:lang', $entry['locale']);
            $xml->writeElement('title', self::text($entry['title']));
            $xml->writeElement('id', $entry['url']);
            self::link($xml, 'alternate', $entry['url'], 'text/html');
            $xml->writeElement('published', $entry['published']);
            $xml->writeElement('updated', $entry['updated']);
            if ($entry['author'] !== null) {
                $xml->startElement('author');
                $xml->writeElement('name', self::text($entry['author']));
                $xml->endElement();
            }
            if ($entry['category'] !== null) {
                $xml->startElement('category');
                $xml->writeAttribute('term', self::text($entry['category']));
                $xml->endElement();
            }
            if ($entry['summary'] !== null) {
                $xml->writeElement('summary', self::text($entry['summary']));
            }
            // The text is HTML, cleaned when it was saved; here it travels
            // as escaped text that the reader turns back into HTML.
            $xml->startElement('content');
            $xml->writeAttribute('type', 'html');
            $xml->text(self::text($entry['body']));
            $xml->endElement();
            $xml->endElement();
        }

        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    private static function link(XMLWriter $xml, string $rel, string $href, string $type): void
    {
        $xml->startElement('link');
        $xml->writeAttribute('rel', $rel);
        $xml->writeAttribute('type', $type);
        $xml->writeAttribute('href', $href);
        $xml->endElement();
    }

    /**
     * Escaping is the writer's job, but some characters cannot appear in
     * XML in any form (most control characters); one of them would make
     * the whole feed unreadable.
     */
    private static function text(string $value): string
    {
        $clean = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);

        // null: the value was not valid UTF-8 to begin with.
        return $clean ?? '';
    }
}
