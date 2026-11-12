<?php

declare(strict_types=1);

use CityCouriers\Pc\Dev\Mutation\Controls;
use CityCouriers\Pc\Dev\Mutation\Generated;
use CityCouriers\Pc\Dev\Mutation\Journal;
use CityCouriers\Pc\Dev\Mutation\Outcome;
use CityCouriers\Pc\Dev\Mutation\RunLog;
use CityCouriers\Pc\Dev\Mutation\Status;

/**
 * Dziennik biegu kontrolnego: po jednym mutancie w klasie Przezycie i Zabicie.
 */
function mutationControlJournal(Outcome $survivor, Outcome $victim): Journal
{
    return new Journal(
        [
            $survivor->id => new Generated($survivor->id, Controls::SURVIVOR, 'Pest\\Mutate\\Mutators\\Arithmetic\\PlusToMinus', 15, 15),
            $victim->id => new Generated($victim->id, Controls::VICTIM, 'Pest\\Mutate\\Mutators\\Arithmetic\\PlusToMinus', 15, 15),
        ],
        [$survivor->id => $survivor, $victim->id => $victim],
        [],
    );
}

function mutationControlLog(): RunLog
{
    return RunLog::parse("  2 Mutations for 2 Files created\n  Mutations: 1 untested, 1 tested");
}

function mutationSurvived(): Outcome
{
    return new Outcome('5a', Status::Untested, 0, 2, 'testy', 0.3, '2 passed (2 assertions)');
}

function mutationKilled(): Outcome
{
    return new Outcome('6b', Status::Tested, 1, 2, 'testy', 0.3, '1 failed, 1 pending (1 assertions)');
}

it('kontrola przechodzi: mutant bez asercji przeżył, mutant z asercją zginął przez nieudany test', function (): void {
    expect(Controls::mutation(mutationControlJournal(mutationSurvived(), mutationKilled()), mutationControlLog(), 0))->toBe([]);
});

it('kontrola ujemna: fałszywe zabicie (proces mutanta padł bez testu) odrzuca bieg', function (): void {
    // Tak wyglądał każdy mutant z co najmniej dwoma wzorcami filtra przy wtyczce bez łatki na Windows (#60).
    $falseKill = new Outcome('5a', Status::Tested, 255, 2, 'testy', 0.2, null);
    $reasons = Controls::mutation(mutationControlJournal($falseKill, mutationKilled()), mutationControlLog(), 0);

    expect($reasons)->toHaveCount(1)
        ->and($reasons[0])->toContain('kontrola zerowa: mutant 5a, który musi przeżyć')
        ->and($reasons[0])->toContain('ma status tested (kod 255, testy: nie uruchomiono)');
});

it('kontrola ujemna: mutant z asercją, który przeżył albo zginął bez nieudanego testu, odrzuca bieg', function (Outcome $victim): void {
    $reasons = Controls::mutation(mutationControlJournal(mutationSurvived(), $victim), mutationControlLog(), 0);

    expect($reasons)->toHaveCount(1)
        ->and($reasons[0])->toContain('kontrola dodatnia: mutant 6b');
})->with([
    'przeżył (testy nie biegną)' => [new Outcome('6b', Status::Untested, 0, 2, 'testy', 0.2, '0 passed')],
    'zabity kodem 255 bez testu' => [new Outcome('6b', Status::Tested, 255, 2, 'testy', 0.2, null)],
    'timeout' => [new Outcome('6b', Status::Timeout, null, 2, 'testy', 9.0, null)],
]);

it('kontrola ujemna: kontrola z jednym wzorcem filtra nie sprawdza rozcinania filtra, więc odrzuca bieg', function (): void {
    $single = new Outcome('5a', Status::Untested, 0, 1, 'testy', 0.3, '1 passed');

    expect(implode("\n", Controls::mutation(mutationControlJournal($single, mutationKilled()), mutationControlLog(), 0)))
        ->toContain('ma 1 wzorzec filtra, a kontrola wymaga co najmniej dwóch');
});

it('kontrola ujemna: brak mutantów kontrolnych, brak wyniku albo nieukończony bieg odrzuca bieg', function (): void {
    $empty = Controls::mutation(new Journal([], [], []), RunLog::parse(''), 1);
    $journal = mutationControlJournal(mutationSurvived(), mutationKilled());
    $withoutResult = Controls::mutation(new Journal($journal->generated, [], ['dziennik: błąd']), mutationControlLog(), 0);

    expect($empty)->toBe([
        'kontrola zerowa: bieg kontrolny nie dokończył się (kod 1)',
        'kontrola zerowa: brak mutantów w ' . Controls::SURVIVOR,
        'kontrola zerowa: brak mutantów w ' . Controls::VICTIM,
    ])->and($withoutResult)->toBe([
        'kontrola zerowa: dziennik: błąd',
        'kontrola zerowa: mutant w ' . Controls::SURVIVOR . ' bez wyniku',
        'kontrola zerowa: mutant w ' . Controls::VICTIM . ' bez wyniku',
    ]);
});

it('kontrola tożsamościowa: zielony zestaw pod wrapperem przechodzi, ostrzeżenia odrzucają bieg z odsyłaczem do #73', function (): void {
    $failed = Controls::identity(1, "  \e[90mTests:\e[39m    48 warnings, 328 passed (1200 assertions)\n");

    expect(Controls::identity(0, 'Tests: 376 passed'))->toBe([])
        ->and($failed)->toHaveCount(1)
        ->and($failed[0])->toContain('kończy się kodem 1 (48 warnings, 328 passed (1200 assertions))')
        ->and($failed[0])->toContain('(#73)')
        ->and(Controls::identity(null, '')[0])->toContain('kodem nieznanym (brak podsumowania)');
});
