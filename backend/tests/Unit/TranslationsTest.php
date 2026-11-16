<?php

declare(strict_types=1);

function translationKeysInCode(): array
{
    $root = dirname(__DIR__, 2);
    $files = new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/app")), '/\.php$/');
    $keys = [];

    foreach ([...iterator_to_array($files), "{$root}/bootstrap/app.php"] as $file) {
        preg_match_all('/(?:__|trans_choice)\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/', (string) file_get_contents((string) $file), $matches);

        foreach ($matches[1] as $literal) {
            $keys[] = stripslashes(substr($literal, 1, -1));
        }
    }

    return array_values(array_unique($keys));
}

function placeholdersOf(string $text): array
{
    preg_match_all('/:([a-z_]+)/', $text, $matches);
    $names = array_values(array_unique($matches[1]));
    sort($names);

    return $names;
}

it('translates every sentence the API shows into Portuguese with the same placeholders', function (): void {
    $portuguese = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/lang/pt_BR.json'), true, flags: JSON_THROW_ON_ERROR);
    $used = translationKeysInCode();

    expect(array_values(array_diff($used, array_keys($portuguese))))->toBe([], 'Sentences without a Portuguese translation')
        ->and(array_values(array_diff(array_keys($portuguese), $used)))->toBe([], 'Translations no code uses any more');

    foreach ($portuguese as $english => $translation) {
        expect(placeholdersOf($translation))->toBe(placeholdersOf($english), "Placeholders differ in “{$english}”")
            ->and(substr_count($translation, '|'))->toBe(substr_count($english, '|'), "Plural forms differ in “{$english}”");
    }
});
