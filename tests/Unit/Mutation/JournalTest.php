<?php

declare(strict_types=1);

use CityCouriers\Pc\Dev\Mutation\Journal;
use CityCouriers\Pc\Dev\Mutation\Status;

/**
 * @param array<string, mixed> $fields
 */
function mutationJournalLine(array $fields): string
{
    return json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function mutationGeneratedLine(string $id, string $file = 'C:\\repo\\src\\A.php'): string
{
    return mutationJournalLine(['event' => 'generated', 'id' => $id, 'file' => $file, 'mutator' => 'Pest\\Mutate\\Mutators\\Arithmetic\\PlusToMinus', 'start_line' => 7, 'end_line' => 9]);
}

function mutationResultLine(string $id, string $status = 'tested'): string
{
    return mutationJournalLine(['event' => 'result', 'id' => $id, 'status' => $status, 'exit_code' => 1, 'patterns' => 2, 'filter' => 'testy', 'duration' => 0.5, 'summary' => '1 failed']);
}

it('czyta wygenerowane mutanty i wyniki, ścieżki względem repo z ukośnikami', function (): void {
    $journal = Journal::parse(implode("\n", [mutationGeneratedLine('aa01'), mutationResultLine('aa01'), '']), 'c:/repo/');

    expect($journal->errors)->toBe([])
        ->and($journal->generated['aa01']->file)->toBe('src/A.php')
        ->and($journal->generated['aa01']->mutatorName())->toBe('PlusToMinus')
        ->and($journal->generated['aa01']->startLine)->toBe(7)
        ->and($journal->generated['aa01']->endLine)->toBe(9)
        ->and($journal->outcomes['aa01']->status)->toBe(Status::Tested)
        ->and($journal->outcomes['aa01']->exitCode)->toBe(1)
        ->and($journal->outcomes['aa01']->patterns)->toBe(2)
        ->and($journal->outcomes['aa01']->filter)->toBe('testy')
        ->and($journal->outcomes['aa01']->duration)->toBe(0.5)
        ->and($journal->outcomes['aa01']->summary)->toBe('1 failed');
});

it('ścieżka spoza repo zostaje bezwzględna', function (): void {
    expect(Journal::relative('D:\\inne\\src\\A.php', 'C:\\repo'))->toBe('D:/inne/src/A.php')
        ->and(Journal::relative('C:\\Repo\\src\\A.php', 'c:\\repo'))->toBe('src/A.php');
});

it('kontrola ujemna: każdy uszkodzony wiersz dziennika jest błędem, nie pominięciem', function (string $content, string $error): void {
    $journal = Journal::parse($content, 'C:\\repo');

    expect(implode("\n", $journal->errors))->toContain($error);
})->with([
    'nie JSON' => ['{zepsute', 'wiersz 1: to nie jest obiekt JSON'],
    'brak identyfikatora' => [mutationJournalLine(['event' => 'generated']), 'brak poprawnego identyfikatora'],
    'nieznane zdarzenie' => [mutationJournalLine(['event' => 'inne', 'id' => 'ab']), 'nieznane zdarzenie'],
    'mutant dwa razy' => [mutationGeneratedLine('ab') . "\n" . mutationGeneratedLine('ab'), 'wygenerowany dwa razy'],
    'dwa wyniki' => [mutationGeneratedLine('ab') . "\n" . mutationResultLine('ab') . "\n" . mutationResultLine('ab'), 'ma dwa wyniki'],
    'wynik bez wygenerowania' => [mutationResultLine('ab'), 'wynik mutanta ab bez wpisu o wygenerowaniu'],
    'nieznany status' => [mutationGeneratedLine('ab') . "\n" . mutationResultLine('ab', 'zabity'), 'niepełny wynik mutanta ab'],
    'niepełne wygenerowanie' => [mutationJournalLine(['event' => 'generated', 'id' => 'ab', 'file' => 'x']), 'niepełny wpis o wygenerowaniu'],
]);
