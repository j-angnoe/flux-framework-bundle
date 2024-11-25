<?php

namespace Flux\Framework\Chain;

trait DownloaderTrait {

    /**
     * The completeHeaders will be available after
     * the chain is terminated (by run(), output() or whatever.)\
     * The collected headers are stored into the first argument.
     */
    function lazilyCollectHeadersFromSampleInto(&$completeHeaders) { 
        $this->fromJsonlines()->apply(function($iterator) use (&$completeHeaders) { 
            $iterator = new \NoRewindIterator($iterator);
            $cur = $iterator->current() ?? null;
            $cur = is_array($cur) ? $cur : [];
            $headers = array_flip(array_keys($cur));
            $buffer = [];
            $index = 0;
            foreach ($iterator as $i) { 
                $index++;
                $headers = $headers + array_flip(array_keys($i));

                if ($index > 100) {
                    break;
                }
                $buffer[] = $i;
            }
            $completeHeaders = array_flip($headers);

            foreach ($buffer as $b) yield $b;
            foreach ($iterator as $i) yield $i;
        });
    }

    function outputDownload(string $type, array $options = []) { 
        $filename = $options['filename'] ?? 'pipeline-download-';

        $completeHeaders = [];

        $chain = $this;

        $chain->fromJsonlines();
        $chain->lazilyCollectHeadersFromSampleInto($completeHeaders);

        // Ensure we gather the complete headers by sampling the first 100 (or so) records.        
        switch($type) { 
            case 'list':
                return $chain->map(function($row) { 
                    if (array_key_exists('customer', $row)) {
                        return $row['customer'];
                    } else {
                        return null;
                    }
                })->filter()->unique()->values();

            case 'json':
            case 'jsonlines':
                header('Content-type: application/jsonlines');
                header('Content-Disposition: attachment; filename="'.$filename.'-'.date('YmdHi').'.jsonl"');
                $chain->output();
                break;
            case 'excel':
                if (!class_exists('XLSXWriter')) { 
                    throw new \Exception('XLSXWriter is missing');
                }
            
                $chain
                ->apply(function($iterator) use ($filename, &$completeHeaders) { 
                    $iterator->current();
                    $writer = new \XLSXWriter();
                    $sheetName = $filename;
                    $writer->writeSheetHeader($sheetName, []);

                    // $excelOptions = [
                    //     'freeze_rows' => 1,
                    //     'freeze_columns' => 1,
                    //     'auto_filter' => true
                    // ];
                    $excelOptions = [];

                    $writer->writeSheetRow($sheetName, $completeHeaders, $excelOptions);
                    foreach ($iterator as $line) { 
                        $orderedLine = [];
                        foreach ($completeHeaders as $h) { 
                            $orderedLine[$h] = $line[$h] ?? null;
                        }
                        $writer->writeSheetRow($sheetName, $orderedLine);
                    }

                    header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
                    header('Content-Transfer-Encoding: binary');
                    header('Content-Disposition: attachment; filename="'.$filename.'-'.date('YmdHi').'.xlsx"');

                    $writer->writeToStdOut();

                    yield null;
                })->run();
                break;
            case 'tsv':
            case 'csv':

                $chain
                ->apply(function($iterator) use ($filename, &$completeHeaders) { 
                    $iterator->current();

                    $fh = fopen('php://output', 'w');

                    header('Content-type:text/csv');
                    header('Content-Disposition: attachment; filename="'.$filename.'-'.date('YmdHi').'.csv"');

                    fputcsv($fh, $completeHeaders);
                    foreach ($iterator as $key=>$item) {
                        $orderedRow = [];
                        foreach ($completeHeaders as $h) { 
                            $value = $item[$h] ?? null;
                            if (is_scalar($value)) { 
                                // Prevent CSV Macro stuff... remove = signs from start.
                                $orderedRow[] = ltrim('=', $value);
                            } else {
                                $orderedRow[] = '[array]';
                            }
                        }
                        fputcsv($fh, $orderedRow);
                    }
                    yield '';
                })
                ->run();
                break;
            case 'print':

                if (isset($_POST[sha1($filename)])) {
                    $chain->quicksearch($_POST[sha1($filename)]);
                }
                if (isset($_POST['excel'])) { 
                    $chain->outputDownload('excel', $options);
                }

                do { 
                    ob_end_flush();
                } while(ob_get_level());

                header('Content-type: text/html');


                echo '<div class="dont-print">';
                echo '<form method="post" style="display: inline-block;">
                    <input type="hidden" name="rpc" value="'.htmlentities($_POST['rpc']).'">';
                echo 'Filter: <input type="text" name="'.sha1($filename).'" value="'.htmlentities($_POST[sha1($filename)] ?? '').'">
                    <input type="submit" value="Update">';
                echo '</form>';

                if (class_exists('XlsxWriter')) { 
                    $newPOST = $_POST;
                    $newPOST['excel'] = 1;
                    echo '<form method="post" style="display: inline-block;">';
                    foreach ($newPOST as $k=>$v) { 
                        echo '<input type="hidden" name="'.htmlentities($k).'" value="'.htmlentities($v).'">';
                    }
                    echo '<input type="submit" value="Excel">';
                    echo '</form>';
                }

                echo '<b style="margin-left:12px;">'.$filename.'</b>';
                
                echo '</div>';
                echo '<html>';
                echo '<title>'. htmlentities($filename).'</title>';
                echo '<style>
                @media print {
                    .dont-print {
                        display:none;
                    }
                }
                body, th, td { 
                    font-family: sans;
                    font-size: 12pt;
                    vertical-align: top;
                }
                table { 
                    border:1px solid black;
                    border-collapse: collapse;
                }
                th {
                    position: sticky;
                    top: 0;
                    background: white;
                }
                th, td {
                    border: 1px solid black;
                    padding: 5px;
                }
                .summary { 
                    position: absolute;
                    right: 0;
                    top: 0;
                    padding-top: 10px;
                    padding-right: 10px;
                }
                </style>';
                echo '<body>';

                echo '<table border=1>';
                $chain
                ->apply(function($iterator) use (&$completeHeaders) { 
                    $iterator = new \NoRewindIterator($iterator);
                    $iterator->current();

                    echo '<thead><tr>';
                    echo '<th style="user-select:none;">#</th>';
                    foreach ($completeHeaders as $h) { 
                        echo '<th>' . htmlentities($h) . '</th>';
                    }
                    echo '</tr></thead>' . PHP_EOL;
                    $outputtedRows = 0;

                    foreach ($iterator as $b) { 
                        echo '<tr>';
                        echo '<td style="user-select:none;text-align:right;">'.($outputtedRows+1).'</td>';
                        foreach ($completeHeaders as $h) {
                            $b[$h] ??= '';
                            if (is_scalar($b[$h])) { 
                                echo '<td>'.htmlentities($b[$h] ?? '').'</td>';
                            } else {
                                echo '<td><pre>' . htmlentities(json_encode($b[$h], JSON_PRETTY_PRINT)) . '</pre></td>';
                            }
                        }
                        echo '</tr>' . PHP_EOL;
                        $outputtedRows += 1;
                    }
                    echo '</table>';

                    echo '<div class="dont-print summary">
                        '.$outputtedRows . ' rows, ' . count($completeHeaders) . ' columns
                    </div>';
                    yield null;
                })->run();
            break;
        };
        exit;
    }
}