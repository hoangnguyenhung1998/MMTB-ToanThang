<?php

namespace App\Services\Reconciliation;

use Illuminate\Support\Facades\DB;

class RelationshipBatchWriter
{
    public static function update(string $table, array $updates, string $now, array $scope = []): void
    {
        $grammar = DB::connection()->getQueryGrammar();
        foreach (array_chunk($updates, 50, true) as $chunk) {
            $columns = array_unique(array_merge(...array_map('array_keys', $chunk)));
            $sets = [];
            $bindings = [];
            foreach ($columns as $column) {
                $wrapped = $grammar->wrap($column);
                $case = "$wrapped = CASE ".$grammar->wrap('id');
                foreach ($chunk as $id => $changes) {
                    if (array_key_exists($column, $changes)) {
                        $case .= ' WHEN ? THEN ?';
                        array_push($bindings, $id, $changes[$column]);
                    }
                }
                $sets[] = $case." ELSE $wrapped END";
            }
            $sets[] = $grammar->wrap('updated_at').' = ?';
            $bindings[] = $now;
            $where = [];
            foreach ($scope as $column => $value) {
                $where[] = $grammar->wrap($column).' = ?';
                $bindings[] = $value;
            }
            $where[] = $grammar->wrap('id').' IN ('.implode(',', array_fill(0, count($chunk), '?')).')';
            array_push($bindings, ...array_keys($chunk));
            DB::update('UPDATE '.$grammar->wrapTable($table).' SET '.implode(', ', $sets).' WHERE '.implode(' AND ', $where), $bindings);
        }
    }
}
