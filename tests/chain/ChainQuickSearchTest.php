<?php

use Flux\Framework\Chain\Chain;
use Flux\Framework\Chain\QuickSearchTrait;
use PHPUnit\Framework\TestCase;

if (!class_exists('UnitTest_ChainWithQuickSearch')) {
    class UnitTest_ChainWithQuickSearch extends Chain {
        use QuickSearchTrait;
    }
}

class ChainQuickSearchTest extends TestCase {
    private array $data;

    function getDataSource(): Chain { 
        $this->data ??= (new UnitTest_ChainWithQuickSearch(range(1,100)))
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
        
        return (new UnitTest_ChainWithQuickSearch($this->data));
    }

    function testQuickSearch(): void { 
        // $this->getDataSource()->output();

        // $this->getDataSource()->quicksearch('hombre')->output();
        // $this->getDataSource()->quicksearch('-femalis')->output();
        
        $subjectCount1 = $this->getDataSource()->quicksearch('hombre')->count();
        $subjectCount2 = $this->getDataSource()->quicksearch('-femalis')->count();

        $this->assertTrue($subjectCount1 > 10 && $subjectCount1 < 100, 'Subject count 1 ('.$subjectCount1.') should be >10 and <100');
        $this->assertTrue($subjectCount2 > 10 && $subjectCount2 < 100, 'Subject count 2 ('.$subjectCount2.') should be >10 and <100');
        $this->assertEquals($subjectCount1, $subjectCount2, 'Subject counts should be equal');
    }

    function testQuickSearchModifiers(): void {
        $subjectCount1 = $this->getDataSource()->quicksearch('id=83')->count();
        $this->assertEquals(1,$subjectCount1);

        $subjectCount1 = $this->getDataSource()->quicksearch('id>90')->count();
        $this->assertEquals(10,$subjectCount1);

    
        $subjectCount1 = $this->getDataSource()->quicksearch('age>30')->count();
        $this->assertTrue($subjectCount1 > 20);


        $subjectCount1 = $this->getDataSource()->quicksearch('role!=manager')->count();
        $this->assertGreaterThan(50, $subjectCount1, 'There should be more then 50 non-managers');


        $subjectCount1 = $this->getDataSource()->quicksearch('role:manager')->count();
        $this->assertLessThanOrEqual(50, $subjectCount1, 'There should be more then 50 non-managers');

        $subjectCount1 = $this->getDataSource()->quicksearch('-role:manager')->count();
        $this->assertGreaterThan(50, $subjectCount1, 'There should be more then 50 non-managers');
    }
}