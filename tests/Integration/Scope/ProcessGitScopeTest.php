<?php

declare(strict_types=1);

use CityCouriers\Pc\Engine\ProcessGit;
use CityCouriers\Pc\Tests\Support\GitRepo;

it('prawdziwy git: ProcessGit podaje ścieżki do decyzji o zakresie (suma HEAD i HEAD^) albo null, gdy zakresu nie da się zawęzić', function (): void {
    $repo = new GitRepo();
    $repo->history([['README.md' => "r\n"], ['src/Kod.php' => "<?php\n"]]);
    $repo->write('docs/a.md', "a\n");
    $repo->stage('docs/a.md');
    $git = new ProcessGit($repo->path);

    expect($git->scopePaths())->toContain('docs/a.md', 'src/Kod.php');

    $repo->git('reset', '--quiet');
    expect($git->scopePaths())->toBeNull();
    $repo->remove();
});
