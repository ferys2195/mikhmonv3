<?php
/*
 * Mikhmon Report Helper
 * Provides universal date parsing, filtering, and synchronization for RouterOS v6 and v7 report scripts.
 */

if (!function_exists('mikhmon_parse_date')) {
    function mikhmon_parse_date($date_str) {
        $m_map = array(
            "jan" => "01", "feb" => "02", "mar" => "03", "apr" => "04",
            "may" => "05", "jun" => "06", "jul" => "07", "aug" => "08",
            "sep" => "09", "oct" => "10", "nov" => "11", "dec" => "12"
        );
        $m_rev = array(
            "01" => "jan", "02" => "feb", "03" => "mar", "04" => "apr",
            "05" => "may", "06" => "jun", "07" => "jul", "08" => "aug",
            "09" => "sep", "10" => "oct", "11" => "nov", "12" => "dec",
            "1" => "jan", "2" => "feb", "3" => "mar", "4" => "apr",
            "5" => "may", "6" => "jun", "7" => "jul", "8" => "aug",
            "9" => "sep"
        );

        $date_str = strtolower(trim($date_str));
        if (empty($date_str)) return false;

        // Format 1: YYYY-MM-DD or YYYY/MM/DD (e.g. 2026-10-19)
        if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})/', $date_str, $m)) {
            $year = $m[1];
            $month_num = str_pad($m[2], 2, "0", STR_PAD_LEFT);
            $day = str_pad($m[3], 2, "0", STR_PAD_LEFT);
            $month = isset($m_rev[$month_num]) ? $m_rev[$month_num] : "";
            if ($month != "") {
                return array(
                    'year' => $year,
                    'month' => $month,
                    'month_num' => $month_num,
                    'day' => $day,
                    'idbl' => $month . $year,
                    'norm_date' => $month . "/" . $day . "/" . $year,
                    'iso_date' => $year . "-" . $month_num . "-" . $day
                );
            }
        }

        // Format 2: mmm/DD/YYYY or mmm-DD-YYYY (e.g. oct/19/2026 or oct-19-2026)
        if (preg_match('/^([a-z]{3})[-\/](\d{1,2})[-\/](\d{4})/', $date_str, $m)) {
            $month = $m[1];
            $day = str_pad($m[2], 2, "0", STR_PAD_LEFT);
            $year = $m[3];
            $month_num = isset($m_map[$month]) ? $m_map[$month] : "";
            if ($month_num != "") {
                return array(
                    'year' => $year,
                    'month' => $month,
                    'month_num' => $month_num,
                    'day' => $day,
                    'idbl' => $month . $year,
                    'norm_date' => $month . "/" . $day . "/" . $year,
                    'iso_date' => $year . "-" . $month_num . "-" . $day
                );
            }
        }

        // Format 3: DD/mmm/YYYY or DD-mmm-YYYY (e.g. 19/oct/2026)
        if (preg_match('/^(\d{1,2})[-\/]([a-z]{3})[-\/](\d{4})/', $date_str, $m)) {
            $day = str_pad($m[1], 2, "0", STR_PAD_LEFT);
            $month = $m[2];
            $year = $m[3];
            $month_num = isset($m_map[$month]) ? $m_map[$month] : "";
            if ($month_num != "") {
                return array(
                    'year' => $year,
                    'month' => $month,
                    'month_num' => $month_num,
                    'day' => $day,
                    'idbl' => $month . $year,
                    'norm_date' => $month . "/" . $day . "/" . $year,
                    'iso_date' => $year . "-" . $month_num . "-" . $day
                );
            }
        }

        // Format 4: MM/DD/YYYY (e.g. 10/19/2026)
        if (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})/', $date_str, $m)) {
            $month_num = str_pad($m[1], 2, "0", STR_PAD_LEFT);
            $day = str_pad($m[2], 2, "0", STR_PAD_LEFT);
            $year = $m[3];
            $month = isset($m_rev[$month_num]) ? $m_rev[$month_num] : "";
            if ($month != "") {
                return array(
                    'year' => $year,
                    'month' => $month,
                    'month_num' => $month_num,
                    'day' => $day,
                    'idbl' => $month . $year,
                    'norm_date' => $month . "/" . $day . "/" . $year,
                    'iso_date' => $year . "-" . $month_num . "-" . $day
                );
            }
        }

        // Format 5: idbl format directly e.g. oct2026 or 2026-10 or 202610
        if (preg_match('/^([a-z]{3})(\d{4})$/', $date_str, $m)) {
            $month = $m[1];
            $year = $m[2];
            if (isset($m_map[$month])) {
                return array(
                    'year' => $year,
                    'month' => $month,
                    'month_num' => $m_map[$month],
                    'day' => '',
                    'idbl' => $month . $year,
                    'norm_date' => '',
                    'iso_date' => ''
                );
            }
        }
        if (preg_match('/^(\d{4})[-]?(\d{2})$/', $date_str, $m)) {
            $year = $m[1];
            $month_num = $m[2];
            if (isset($m_rev[$month_num])) {
                $month = $m_rev[$month_num];
                return array(
                    'year' => $year,
                    'month' => $month,
                    'month_num' => $month_num,
                    'day' => '',
                    'idbl' => $month . $year,
                    'norm_date' => '',
                    'iso_date' => ''
                );
            }
        }

        return false;
    }
}

if (!function_exists('mikhmon_get_script_date')) {
    function mikhmon_get_script_date($row) {
        if (!empty($row['name'])) {
            $parts = explode("-|-", $row['name']);
            if (!empty($parts[0])) {
                $parsed = mikhmon_parse_date($parts[0]);
                if ($parsed && !empty($parsed['month'])) return $parsed;
            }
        }
        if (!empty($row['source'])) {
            $parsed = mikhmon_parse_date($row['source']);
            if ($parsed && !empty($parsed['month'])) return $parsed;
        }
        if (!empty($row['owner'])) {
            $parsed = mikhmon_parse_date($row['owner']);
            if ($parsed && !empty($parsed['month'])) return $parsed;
        }
        return false;
    }
}

if (!function_exists('mikhmon_filter_scripts')) {
    function mikhmon_filter_scripts($scripts, $target_idbl = '', $target_idhr = '') {
        $result = array();
        $target_idbl = strtolower(trim($target_idbl));
        $parsed_idbl = !empty($target_idbl) ? mikhmon_parse_date($target_idbl) : false;
        $target_idbl_key = $parsed_idbl ? $parsed_idbl['idbl'] : $target_idbl;

        $parsed_idhr = !empty($target_idhr) ? mikhmon_parse_date($target_idhr) : false;

        foreach ($scripts as $row) {
            $parsed = mikhmon_get_script_date($row);
            $parts = explode("-|-", isset($row['name']) ? $row['name'] : '');
            $raw_date = isset($parts[0]) ? $parts[0] : '';

            if (!empty($target_idhr)) {
                $matched = false;
                if ($parsed && $parsed_idhr) {
                    if ($parsed['norm_date'] === $parsed_idhr['norm_date'] || $parsed['iso_date'] === $parsed_idhr['iso_date']) {
                        $matched = true;
                    }
                } else {
                    if ($raw_date === $target_idhr || (isset($row['source']) && $row['source'] === $target_idhr)) {
                        $matched = true;
                    }
                }
                if ($matched) {
                    $result[] = $row;
                }
            } elseif (!empty($target_idbl_key)) {
                $matched = false;
                if ($parsed && $parsed['idbl'] === $target_idbl_key) {
                    $matched = true;
                } elseif (isset($row['owner']) && strtolower($row['owner']) === $target_idbl_key) {
                    $matched = true;
                }
                if ($matched) {
                    $result[] = $row;
                }
            } else {
                $result[] = $row;
            }
        }
        return $result;
    }
}

if (!function_exists('mikhmon_fetch_and_sync_report')) {
    function mikhmon_fetch_and_sync_report($API, $target_idbl = '', $target_idhr = '') {
        $rawScripts = array();
        $target_idhr = trim($target_idhr);
        $target_idbl = trim($target_idbl);

        if (!empty($target_idhr)) {
            // Target by Day (idhr) - Query ROS 6 format and ROS 7 format
            $parsed_hr = mikhmon_parse_date($target_idhr);
            $query_sources = array();
            if ($parsed_hr) {
                if (!empty($parsed_hr['norm_date'])) $query_sources[] = $parsed_hr['norm_date']; // e.g. oct/19/2026
                if (!empty($parsed_hr['iso_date']) && $parsed_hr['iso_date'] !== $parsed_hr['norm_date']) {
                    $query_sources[] = $parsed_hr['iso_date']; // e.g. 2026-10-19
                }
            }
            if (!in_array($target_idhr, $query_sources)) {
                $query_sources[] = $target_idhr;
            }

            $seen_ids = array();
            foreach ($query_sources as $src) {
                $res = $API->comm("/system/script/print", array("?source" => $src));
                if (is_array($res)) {
                    foreach ($res as $item) {
                        $id = isset($item['.id']) ? $item['.id'] : (isset($item['name']) ? $item['name'] : count($seen_ids));
                        if (!isset($seen_ids[$id])) {
                            $seen_ids[$id] = true;
                            $rawScripts[] = $item;
                        }
                    }
                }
            }

            // Fallback: if targeted query returned empty (e.g. scripts have non-standard source), query ?comment=mikhmon
            if (empty($rawScripts)) {
                $raw = $API->comm("/system/script/print", array("?comment" => "mikhmon"));
                if (is_array($raw)) {
                    $rawScripts = $raw;
                }
            }
        } elseif (!empty($target_idbl)) {
            // Target by Month (idbl) - Query ROS 6 format and ROS 7 format
            $parsed_bl = mikhmon_parse_date($target_idbl);
            $query_owners = array();
            if ($parsed_bl) {
                if (!empty($parsed_bl['idbl'])) $query_owners[] = $parsed_bl['idbl']; // e.g. oct2026
                $iso_owner = $parsed_bl['year'] . "-" . $parsed_bl['month_num']; // e.g. 2026-10
                if (!in_array($iso_owner, $query_owners)) $query_owners[] = $iso_owner;
            }
            if (!in_array(strtolower($target_idbl), $query_owners)) {
                $query_owners[] = strtolower($target_idbl);
            }

            $seen_ids = array();
            foreach ($query_owners as $own) {
                $res = $API->comm("/system/script/print", array("?owner" => $own));
                if (is_array($res)) {
                    foreach ($res as $item) {
                        $id = isset($item['.id']) ? $item['.id'] : (isset($item['name']) ? $item['name'] : count($seen_ids));
                        if (!isset($seen_ids[$id])) {
                            $seen_ids[$id] = true;
                            $rawScripts[] = $item;
                        }
                    }
                }
            }

            // Fallback: if targeted query returned empty (e.g. scripts have owner="admin" or corrupted format), query ?comment=mikhmon
            if (empty($rawScripts)) {
                $raw = $API->comm("/system/script/print", array("?comment" => "mikhmon"));
                if (is_array($raw)) {
                    $rawScripts = $raw;
                }
            }
        } else {
            // All records: query scripts with comment=mikhmon
            $raw = $API->comm("/system/script/print", array("?comment" => "mikhmon"));
            if (is_array($raw)) {
                $rawScripts = $raw;
            }
        }

        $filtered = mikhmon_filter_scripts($rawScripts, $target_idbl, $target_idhr);

        $normalized = array();
        foreach ($filtered as $row) {
            $parsed = mikhmon_get_script_date($row);

            // Normalize date in script name for consistent display and resume report
            if ($parsed && !empty($parsed['norm_date'])) {
                $parts = explode("-|-", $row['name']);
                if (count($parts) >= 4) {
                    $parts[0] = $parsed['norm_date'];
                    $row['name'] = implode("-|-", $parts);
                }
                $row['_parsed_date'] = $parsed;
            }

            $normalized[] = $row;
        }

        return $normalized;
    }
}
