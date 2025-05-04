<?php

use Flux\Framework\Chain\Chain;

if (!class_exists('UnitTest_ChainWithQuickSearch')) {
    class UnitTest_ChainWithQuickSearch extends Chain
    {
        use \Flux\Framework\Chain\QuickSearchTrait;
    }
}
global $sampleData; 
function getDataSource(): Chain
{
    global $sampleData;

    $sampleData ??= (new UnitTest_ChainWithQuickSearch(range(1,100)))
        ->map(function($id) {
            return [
                'id' => $id,
                'gender' => rand(1,2) === 1 ? 'hombre' : 'femalis',
                'role' => match(rand(0, 3)) {
                    0 => 'worker',
                    1 => 'manager',
                    2 => 'cleaner',
                    3 => 'boss'
                },
                'age' => rand(18,36)
            ];
        })
        ->toArray();

    return (new UnitTest_ChainWithQuickSearch($sampleData));
}

test('quick search', function () {
    // $this->getDataSource()->output();
    // getDataSource()->quicksearch('hombre')->output();
    // getDataSource()->quicksearch('-femalis')->output();
    $subjectCount1 = getDataSource()->quicksearch('hombre')->count();
    $subjectCount2 = getDataSource()->quicksearch('-femalis')->count();

    expect($subjectCount1 > 10 && $subjectCount1 < 100)->toBeTrue('Subject count 1 ('.$subjectCount1.') should be >10 and <100');
    expect($subjectCount2 > 10 && $subjectCount2 < 100)->toBeTrue('Subject count 2 ('.$subjectCount2.') should be >10 and <100');
    expect($subjectCount2)->toEqual($subjectCount1, 'Subject counts should be equal');
});

test('quick search modifiers', function () {
    $subjectCount1 = getDataSource()->quicksearch('id=83')->count();
    expect($subjectCount1)->toEqual(1);

    $subjectCount1 = getDataSource()->quicksearch('id>90')->count();
    expect($subjectCount1)->toEqual(10);

    $subjectCount1 = getDataSource()->quicksearch('age>30')->count();
    expect($subjectCount1 > 20)->toBeTrue();

    $subjectCount1 = getDataSource()->quicksearch('role!=manager')->count();
    expect($subjectCount1)->toBeGreaterThan(50, 'There should be more then 50 non-managers');

    $subjectCount1 = getDataSource()->quicksearch('role:manager')->count();
    expect($subjectCount1)->toBeLessThanOrEqual(50, 'There should be more then 50 non-managers');

    $subjectCount1 = getDataSource()->quicksearch('-role:manager')->count();
    expect($subjectCount1)->toBeGreaterThan(50, 'There should be more then 50 non-managers');
});
