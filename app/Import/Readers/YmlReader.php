<?php
declare(strict_types=1);

namespace App\Import\Readers;

use Generator;
use RuntimeException;
use SimpleXMLElement;
use XMLReader;

/**
 * Потоковое чтение YML-фида (Yandex Market Language) и любого похожего XML,
 * где товары лежат в элементах <offer>.
 *
 * Каждый оффер отдаётся как ассоциативный массив:
 *   'name' => ['Товар'], 'price' => ['100'], '@id' => ['123'], '@available' => ['true']
 */
final class YmlReader
{
    public function __construct(
        private readonly string $path,
        private readonly string $offerElement = 'offer',
    ) {
    }

    /** @return Generator<int, array<string, list<string>>> */
    public function offers(): Generator
    {
        if (!is_readable($this->path)) {
            throw new RuntimeException('Файл фида недоступен для чтения.');
        }

        $reader = new XMLReader();
        if (!$reader->open('file://' . $this->path)) {
            throw new RuntimeException('Не удалось открыть XML-фид.');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $found = false;
            while (@$reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== $this->offerElement) {
                    continue;
                }
                $found = true;
                $xml = $reader->readOuterXml();
                if ($xml === '') {
                    continue;
                }
                $offer = $this->parseOffer($xml);
                if ($offer !== []) {
                    yield $offer;
                }
            }

            if (!$found) {
                throw new RuntimeException(sprintf(
                    'В фиде не найдено ни одного элемента <%s>. Проверьте ссылку и формат файла.',
                    $this->offerElement
                ));
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Первые офферы и список встреченных тегов — для настройки маппинга.
     *
     * @return array{offers:list<array<string,list<string>>>,tags:list<string>}
     */
    public function preview(int $limit = 5): array
    {
        $offers = [];
        $tags = [];
        foreach ($this->offers() as $offer) {
            $offers[] = $offer;
            foreach (array_keys($offer) as $tag) {
                $tags[$tag] = true;
            }
            if (count($offers) >= $limit) {
                break;
            }
        }

        return ['offers' => $offers, 'tags' => array_keys($tags)];
    }

    /** Категории фида: id => название (на будущее и для диагностики). */
    public function categories(): array
    {
        $reader = new XMLReader();
        if (!$reader->open('file://' . $this->path)) {
            return [];
        }

        $categories = [];
        while (@$reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'category') {
                $id = (string) $reader->getAttribute('id');
                $categories[$id] = trim((string) $reader->readString());
            }
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'offer') {
                break;
            }
        }
        $reader->close();

        return $categories;
    }

    /** @return array<string, list<string>> */
    private function parseOffer(string $xml): array
    {
        $element = @simplexml_load_string($xml);
        if (!$element instanceof SimpleXMLElement) {
            return [];
        }

        $offer = [];
        foreach ($element->attributes() ?? [] as $name => $value) {
            $offer['@' . $name][] = trim((string) $value);
        }

        foreach ($element->children() as $child) {
            $name = $child->getName();
            $value = trim((string) $child);

            // <param name="Цвет">Красный</param> -> param:Цвет
            if ($name === 'param') {
                $paramName = trim((string) ($child['name'] ?? ''));
                if ($paramName !== '') {
                    $offer['param:' . $paramName][] = $value;
                    continue;
                }
            }

            $offer[$name][] = $value;
        }

        return $offer;
    }
}
