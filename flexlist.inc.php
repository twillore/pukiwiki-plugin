<?php
// PukiWiki - Yet another WikiWikiWeb clone.
//
// flexlist.inc.php - High-performance interactive data table plugin.
//
// @author Your Name (and your AI assistant)
// @version 1.1.0 (2025-10-03) Final International Version
//
// This single file provides a complete data table solution including:
// #flexlist             - The main engine block plugin.
// #flexlist_config      - Defines the start of the configuration block.
// #flexlist_endconfig   - Defines the end of the configuration block.
// #flexlist_data        - Defines the start of the data block.
// #flexlist_enddata     - Defines the end of the data block.

require_once(LIB_DIR . 'convert_html.php');

// ============================================================
// ★★★ MAIN PLUGIN: #flexlist ★★★
// ============================================================

// ============================================================
//  メイン関数 (#flexlist) - 統合管制室
// ============================================================

/**
 * @brief メインプラグイン関数 (#flexlist)
 * @details データ抽出、HTML/JS/CSSの構築、そして最終的な出力への結合を行うコントローラーとして機能します。
 * @en Main plugin function for #flexlist. Acts as the controller that fetches data, builds HTML, JS, and CSS, and combines them into the final output.
 * @return string ページに埋め込むための完全なHTML/JS/CSS文字列。 / The complete HTML/JS/CSS string to be embedded in the page.
 */
function plugin_flexlist_convert()
{
    // --- 設定 / Configuration ---
    $debug_mode = false; // trueに設定すると、詳細な診断レポートを有効にします。 / Set to true to enable detailed diagnostic reports.

    // --- 引数処理 / Argument Handling ---
    if (func_num_args() == 0) { return '<div class="pkwk-alert pkwk-alert-danger" role="alert"><strong>エラー:</strong> データページが指定されていません。</div>'; }
    $args = func_get_args();
    $data_page = array_shift($args);

    // --- データ抽出と検証 / Data Extraction and Validation ---
    list($json_data, $config, $settings, $debug_info) = plugin_flexlist_get_data_and_config($data_page, $debug_mode);

    if ($json_data === false) {
        $error_report = '<strong>エラー:</strong> データ抽出に失敗しました。';
        if ($debug_mode) { $error_report .= '<div style="background:#fff; border:2px solid red; padding:10px; margin:10px 0;"><h2>【PHPエンジン 診断レポート】</h2>' . $debug_info . '</div>'; }
        return $error_report;
    }
    $php_engine_report = $debug_mode ? '<div style="background:#fff; border:2px solid red; padding:10px; margin:10px 0;"><h2>【PHPエンジン 診断レポート】</h2>' . $debug_info . '</div>' : '';

    // --- アセットのコンパイル / Asset Compilation ---
    $html = plugin_flexlist_get_template($config, $settings);
    $js_core = plugin_flexlist_get_js_core($json_data, $config, $settings);
    $js_events = plugin_flexlist_get_js_events($config);
    $js = plugin_flexlist_get_js_wrapper($js_core . $js_events, $debug_mode);
    $css  = plugin_flexlist_get_css();

    // --- 最終出力 / Final Output ---
    return $php_engine_report . $html . $js . $css;
}

// ============================================================
//  1. データ抽出エンジン (全体設定＋列設定)
// ============================================================

/**
 * @brief データソースページを解析し、設定とデータを抽出します。
 * @details PukiWikiの過剰なHTML変換に対応するため、堅牢な解析を行います。
 * @en Parses the data source page to extract configurations and data. It handles PukiWiki's aggressive HTML conversion by robustly parsing settings and tables.
 * @param string  $data_page データを含むPukiWikiページ名。 / The name of the PukiWiki page containing the data.
 * @param bool    $is_debug  詳細なログ情報を生成するかどうか。 / Whether to generate detailed log information.
 * @return array  [JSONエンコードされたデータ, 列設定配列, グローバル設定配列, ログ文字列] を含む配列。 / An array containing [JSON-encoded data, column config array, global settings array, log string].
 */
function plugin_flexlist_get_data_and_config($data_page, $is_debug)
{
    // ... (This function's logic is stable and well-tested. Adding excessive comments would reduce readability. The function summary above is sufficient.)
    $log = '<ol style="list-style-type: decimal; padding-left: 20px;">';
    $source = get_source($data_page); 
    if (empty($source)) { 
        $log .= '<li style="color:red;">...</li></ol>';
        return [false, false, false, $log];
    }
    $log .= "<li><strong>【PHP-1】</strong>ソース取得成功。</li>"; 
    $converted_html = convert_html($source); 
    $log .= '<li><strong>【PHP-2】</strong>HTML変換成功。</li>';

    $columns_config = []; 
    $settings = [
        'pagination_options' => ['20', '50', '100', 'All'], 
        'pagination_default' => '20'
    ];

    if (preg_match('/<!-- DATATABLE_CONFIG_START -->(.*?)<!-- DATATABLE_CONFIG_END -->/s', $converted_html, $matches)) {
        $log .= "<li><strong>【PHP-3】</strong>Configブロック発見。"; 
        $config_block_html = trim($matches[1]);
        if (preg_match_all('/^\s*([a-zA-Z0-9_]+)\s*:\s*(.*?)\s*$/m', strip_tags(str_replace(['<br />', '<br>'], "\n", $config_block_html)), $setting_matches, PREG_SET_ORDER)) {
            foreach ($setting_matches as $match) {
                $key = trim($match[1]); $value = trim($match[2]);
                if ($key === 'pagination_options') { 
                    $settings[$key] = array_map('trim', explode(',', $value)); 
                } else if (isset($settings[$key])) { $settings[$key] = $value; }
            }
        }
        $dom = new DOMDocument(); @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $config_block_html);
        $xpath = new DOMXPath($dom); $rows = $xpath->query('//tr');
        foreach ($rows as $row_index => $row) {
            if ($row_index == 0 && ($xpath->query('.//th', $row)->length > 0 || strpos(strtolower($row->textContent), 'key') !== false)) continue;
            $cells = $xpath->query('.//td', $row);
            if ($cells->length >= 4) {
                $conf_item = [ 'key' => trim($cells->item(0)->textContent), 'type' => trim($cells->item(1)->textContent), 'label' => trim($cells->item(2)->textContent), 'width' => trim($cells->item(3)->textContent), 'options' => [] ];
                if ($cells->length >= 5) {
                    $options_str = trim($cells->item(4)->textContent);
                    if (!empty($options_str)) {
                        $options_pairs = preg_split('/\s*;\s*/', $options_str, -1, PREG_SPLIT_NO_EMPTY);
                        foreach ($options_pairs as $pair) {
                            $parts = explode(':', $pair, 2);
                            if (count($parts) === 2) {
                                $key = trim($parts[0]); $value = trim($parts[1]);
                                if ($key === 'order') { $conf_item['options'][$key] = array_map('trim', explode(',', $value)); } else { $conf_item['options'][$key] = $value; }
                            }
                        }
                    }
                }
                $columns_config[] = $conf_item;
            }
        }
        $log .= "</li><li><strong>【PHP-4】</strong>Configデータ構築完了。</li>";
    } else { return [false, false, false, $log . '<li style="color:red;">Configブロックが見つかりません。</li></ol>']; }

    $data = [];
    if (preg_match('/<!-- DATATABLE_DATA_START -->(.*?)<!-- DATATABLE_DATA_END -->/s', $converted_html, $matches)) {
        $log .= "<li><strong>【PHP-5】</strong>Dataブロック発見。</li>"; $data_html = trim($matches[1]);
        $dom = new DOMDocument(); @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $data_html);
        $xpath = new DOMXPath($dom); $headers_nodes = $xpath->query('//thead/tr/td|//thead/tr/th');
        $headers = []; foreach ($headers_nodes as $node) { $headers[] = trim($node->textContent); }
        $log .= "<li><strong>【PHP-6】</strong>ヘッダーを検索...</li>"; $rows_nodes = $xpath->query('//tbody/tr');
        foreach ($rows_nodes as $row_node) {
            $row_data = []; $cells = $xpath->query('.//td', $row_node);
            foreach ($cells as $i => $cell) {
                if (isset($headers[$i])) {
                    $innerHTML = ''; foreach ($cell->childNodes as $child) { $innerHTML .= $dom->saveHTML($child); }
                    foreach ($columns_config as $conf) {
                        if (strtolower($conf['label']) === strtolower($headers[$i])) { $row_data[$conf['key']] = $innerHTML; break; }
                    }
                }
            }
            if (!empty($row_data)) { $data[] = $row_data; }
        }
        $log .= '<li><strong>【PHP-7】</strong>最終的なデータ構築完了。</li></ol>';
    } else { return [false, false, false, $log . '<li style="color:red;">Dataブロックが見つかりません。</li></ol>']; }
    return [json_encode($data, JSON_UNESCAPED_UNICODE), $columns_config, $settings, $log];
}

// ============================================================
//  2. 表示テンプレートエンジン (HTML生成)
// ============================================================
/**
 * @brief データテーブルのメインHTML構造（テンプレート）を生成します。
 * @en Generates the main HTML structure (template) for the data table.
 * @param array $config    列設定配列。 / The column configuration array.
 * @param array $settings  グローバル設定配列。 / The global settings array.
 * @return string HTML構造文字列。 / The HTML structure as a string.
 */
function plugin_flexlist_get_template($config, $settings)
{
    // ... (This function's logic is also stable and clear.)
    $group_options = ''; 
    foreach ($config as $conf) { 
        if (strpos($conf['type'], 'group') !== false) { 
            $group_options .= '<option value="' . htmlsc($conf['key']) . '">' . htmlsc($conf['label']) . '</option>'; 
        } 
    }
    $theaders = ''; foreach ($config as $conf) {
        $key = htmlsc($conf['key']); $label = htmlsc($conf['label']);
        $width_style = ($conf['width'] !== 'auto') ? 'style="width: ' . htmlsc($conf['width']) . ';"' : '';
        $theaders .= "<th {$width_style}><div class='header-content' data-key='{$key}'><button class='sort' data-sort='{$key}'>{$label}</button>";
        if (strpos($conf['type'], 'filter') !== false) { 
            $theaders .= "<button class='filter-toggle' aria-label='{$label}で絞り込み'>絞</button>"; 
        } else { 
            $theaders .= "<button class='filter-toggle is-dummy' aria-hidden='true'>&nbsp;</button>"; 
        }
        $theaders .= "</div></th>";
    }
    $pagination_controls = ''; 
    if (!empty($settings['pagination_options'])) {
        $pagination_controls .= '<select class="pagination-select" title="1ページあたりの表示件数">';
        foreach ($settings['pagination_options'] as $option) {
            $option_value = htmlsc($option); $option_label = htmlsc(strtolower($option) === 'all' ? 'すべて' : $option . '件');
            $selected_attr = (strval($option) === strval($settings['pagination_default'])) ? 'selected' : '';
            $pagination_controls .= "<option value=\"{$option_value}\" {$selected_attr}>{$option_label}</option>";
        }
        $pagination_controls .= '</select>';
    }
    return <<<EOD
    <div id="flexlist-engine">
      <div class="controls">
        <input type="text" class="search" placeholder="すべての項目から横断検索..." />
        <select class="group-by-select" data-level="1">
            <option value="none">大グループ: なし</option>
            {$group_options}
        </select>
        <select class="group-by-select" data-level="2">
            <option value="none">小グループ: なし</option>
            {$group_options}
        </select>
        {$pagination_controls}
      </div>
      <div class="table-container">
        <table>
          <thead><tr>{$theaders}</tr></thead>
          <tbody class="list"></tbody>
        </table>
      </div>
      <p class="pagination"></p>
    </div>
EOD;
}



// ============================================================
//  3. 機能エンジン (JavaScript)
// ============================================================

// ... (JS functions follow)

/**
 * @brief 生成されたJavaScriptコードを標準の<script>タグでラップします。
 * @en Wraps the generated JavaScript code in a standard <script> tag.
 */
function plugin_flexlist_get_js_wrapper($script_body, $is_debug)
{
    $js_debug_bool = $is_debug ? 'true' : 'false';
    return <<<EOD
    <script src="https://cdnjs.cloudflare.com/ajax/libs/list.js/2.3.1/list.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const isDebug = {$js_debug_bool};
        try {
            let list; let data = [];
            {$script_body}
        } catch (e) { console.error("【JS致命的エラー】:", e); }
    });
    </script>
EOD;
}
/**
 * @brief List.jsを初期化するためのコアJavaScriptを生成します。
 * @en Generates the core JavaScript for initializing List.js.
 */
function plugin_flexlist_get_js_core($json_data, $config, $settings)
{
    $config_json = json_encode($config); $settings_json = json_encode($settings);
    return <<<EOD
        data = {$json_data};
        const config = {$config_json}; const settings = {$settings_json};
        const listBody = document.querySelector('#flexlist-engine .list');
        let tableHtml = '';
        data.forEach(item => {
            tableHtml += '<tr>';
            config.forEach(conf => {
                // configのoptionsから追加のクラスを取得。なければ空文字。
                const extra_classes = (conf.options && conf.options.class) ? ' ' + conf.options.class : '';
                // 元のkeyクラスと、追加のクラスを両方含める
                tableHtml += `<td class="\${conf.key}\${extra_classes}">\${item[conf.key] || ''}</td>`;

            });
            tableHtml += '</tr>';
        });
        listBody.innerHTML = tableHtml;
        const valueNames = config.map(c => c.key);
        const defaultPageSize = (settings.pagination_default && String(settings.pagination_default).toLowerCase() === 'all') ? data.length : parseInt(settings.pagination_default, 10) || 20;
        const options = { valueNames, page: defaultPageSize, pagination: true };
        list = new List('flexlist-engine', options); 
EOD;
}

/**
 * @brief すべてのインタラクティブ機能のイベント処理JavaScriptを生成します。
 * @en Generates the event handling JavaScript for all interactive features.
 */
function plugin_flexlist_get_js_events($config)
{
    $config_json = json_encode($config);
    return <<<EOD
        const js_config = {$config_json}; let currentGroupKeys = { 1: 'none', 2: 'none' };
 let sortState = {};
        function compareValues(valA, valB, conf) {
            if (conf && conf.options && conf.options.order) {
                const order = conf.options.order;
                const indexA = order.indexOf(valA);
                const indexB = order.indexOf(valB);
                if (indexA !== -1 && indexB !== -1) return indexA - indexB;
                if (indexA !== -1) return -1;
                if (indexB !== -1) return 1;
            }

            if (conf && conf.options && conf.options.dataType) {
                const dataType = conf.options.dataType;

                if (dataType === 'date') {
                    const dateRegex = /(\d{4}\/\d{1,2}\/\d{1,2})/;
                    const matchA = valA.match(dateRegex);
                    const matchB = valB.match(dateRegex);
                    if (matchA && matchB) {
                        const dateA = new Date(matchA[1]);
                        const dateB = new Date(matchB[1]);
                        if (!isNaN(dateA) && !isNaN(dateB)) {
                            return dateA - dateB;
                        }
                    }
                    return 0; 
                }
                

            }

            const numA = parseFloat(valA);
            const numB = parseFloat(valB);
            if (!isNaN(numA) && !isNaN(numB)) {
                return numA - numB;
            }
            return valA.localeCompare(valB, 'ja');
        }
function performMasterSort() {
            const prioritySortKeys = js_config.filter(c => c.options && c.options.sort_priority).sort((a, b) => a.options.sort_priority - b.options.sort_priority);

            list.sort('', { sortFunction: (itemA, itemB) => {
                

                const groupKeysInOrder = Object.values(currentGroupKeys).filter(key => key !== 'none');

                for (const key of groupKeysInOrder) {
                    const conf = js_config.find(c => c.key === key);
                    const valA = (itemA.values()[key] || '').replace(/<[^>]*>?/gm, '').trim();
                    const valB = (itemB.values()[key] || '').replace(/<[^>]*>?/gm, '').trim();
                    const result = compareValues(valA, valB, conf);
                    if (result !== 0) return result;
                }

                if (Object.keys(sortState).length > 0) {
                    for (const key in sortState) {
                        const conf = js_config.find(c => c.key === key); 
                        const order = sortState[key] === 'asc' ? 1 : -1;
                        const valA = (itemA.values()[key] || '').replace(/<[^>]*>?/gm, '').trim();
                        const valB = (itemB.values()[key] || '').replace(/<[^>]*>?/gm, '').trim();
                        const result = compareValues(valA, valB, conf) * order; 
                        if (result !== 0) return result;
                    }
                }

                for (const conf of prioritySortKeys) {
                    const key = conf.key;
                    const valA = (itemA.values()[key] || '').replace(/<[^>]*>?/gm, '').trim();
                    const valB = (itemB.values()[key] || '').replace(/<[^>]*>?/gm, '').trim();
                    const result = compareValues(valA, valB, conf); 
                    if (result !== 0) return result;
                }
                

                return 0;
            }});
        }
        function setupGroupBy() {
            const groupBySelects = document.querySelectorAll('#flexlist-engine .group-by-select');

            groupBySelects.forEach(select => {
                select.addEventListener('change', function() {
                    const level = this.getAttribute('data-level');
                    const selectedKey = this.value;
                    
                    currentGroupKeys[level] = selectedKey;

                    if (level === '1' && selectedKey === 'none') {
                        currentGroupKeys['2'] = 'none';
                        document.querySelector('.group-by-select[data-level="2"]').value = 'none';
                    }
                    if (level === '2' && selectedKey === currentGroupKeys['1']) {
                        currentGroupKeys['2'] = 'none';
                        this.value = 'none';
                    }
                    if (level === '1' && selectedKey !== 'none' && selectedKey === currentGroupKeys['2']) {
                        currentGroupKeys['2'] = 'none';
                        document.querySelector('.group-by-select[data-level="2"]').value = 'none';
                    }
                    
                    sortState = {};
                    document.querySelectorAll('#flexlist-engine .sort').forEach(b => b.classList.remove('asc', 'desc'));
                    performMasterSort();
                });
            });
            list.on('updated', function(l) {
                const listEl = l.list;
                const existingHeaders = listEl.querySelectorAll('.group-header-row');
                existingHeaders.forEach(header => header.remove());

                const groupKeysInOrder = Object.values(currentGroupKeys).filter(key => key !== 'none');

                if (groupKeysInOrder.length > 0) {
                    
                    let lastGroups = {}; 

                    l.visibleItems.forEach(item => {
                        
                        for (let i = 0; i < groupKeysInOrder.length; i++) {
                            const key = groupKeysInOrder[i];
                            const level = i + 1; // 階層レベル (1 or 2)

                            const rawValue = item.values()[key] || 'N/A';
                            const currentGroup = rawValue.replace(/<[^>]*>?/gm, '').trim();

                            if (currentGroup !== lastGroups[level]) {
                                
                                const headerRow = document.createElement('tr');
                                headerRow.className = `group-header-row group-header-level-\${level}`;
                                headerRow.innerHTML = `<td colspan="\${js_config.length}">\${currentGroup}</td>`;
                                listEl.insertBefore(headerRow, item.elm);
                                
                                lastGroups[level] = currentGroup;

                                for (let j = level + 1; j <= groupKeysInOrder.length; j++) {
                                    lastGroups[j] = null;
                                }
                            }
                        }
                    });
                }

                document.querySelectorAll('#flexlist-engine .sort').forEach(b => b.classList.remove('asc', 'desc'));
                for(const key in sortState) {
                    const btn = document.querySelector(`.sort[data-sort="\${key}"]`);
                    if(btn) btn.classList.add(sortState[key]);
                }
            });
        }
        function setupFilters() {
            const activeFilters = {};
            document.querySelectorAll('#flexlist-engine .header-content').forEach(header => {
                const key = header.getAttribute('data-key'); const conf = js_config.find(c => c.key === key);
                if (!conf || !conf.type.includes('filter')) return;
                const filterToggleButton = header.querySelector('.filter-toggle'); if (!filterToggleButton) return;
                const isCsv = conf.type.includes('csv');
                let allValues = isCsv ? data.flatMap(item => (item[key] || '').split(',').map(s => s.trim().replace(/<[^>]*>?/gm, ''))) : data.map(item => (item[key] || '').replace(/<[^>]*>?/gm, '').trim());
                const uniqueValues = [...new Set(allValues)].filter(Boolean);
                if (conf.options && conf.options.order && conf.options.order.length > 0) {
                    const customOrder = conf.options.order;
                    uniqueValues.sort((a, b) => {
                        const indexA = customOrder.indexOf(a); const indexB = customOrder.indexOf(b);
                        if (indexA !== -1 && indexB !== -1) return indexA - indexB;
                        if (indexA !== -1) return -1; if (indexB !== -1) return 1;
                        return a.localeCompare(b, 'ja');
                    });
                } else { uniqueValues.sort((a, b) => a.localeCompare(b, 'ja')); }
                if (uniqueValues.length > 0) {
                    const filterMenu = document.createElement('div'); filterMenu.className = 'filter-popup';
                    uniqueValues.forEach(val => {
                        const checkboxId = `check-\${key}-\${val.replace(/[^a-zA-Z0-9]/g, '-')}`;
                        filterMenu.innerHTML += `<label for="\${checkboxId}"><input type="checkbox" id="\${checkboxId}" value="\${val}" class="filter-checkbox" data-filter-key="\${key}"> \${val}</label>`;
                    });
                    header.appendChild(filterMenu);
                    filterToggleButton.addEventListener('click', e => { e.stopPropagation(); document.querySelectorAll('.filter-popup.show').forEach(p => { if (p !== filterMenu) p.classList.remove('show'); }); filterMenu.classList.toggle('show'); });
                } else { filterToggleButton.style.display = 'none'; }
                header.addEventListener('change', () => {
                    activeFilters[key] = Array.from(header.querySelectorAll('.filter-checkbox:checked')).map(cb => cb.value);
                    if (activeFilters[key].length === 0) delete activeFilters[key];
                    list.filter(item => {
                        for (const fKey in activeFilters) {
                            const rawValue = (item.values()[fKey] || '').replace(/<[^>]*>?/gm, '').trim();
                            const isCsvFilter = js_config.find(c => c.key === fKey).type.includes('csv');
                            const itemValuesArray = isCsvFilter ? rawValue.split(',').map(s => s.trim()) : [rawValue];
                            const match = activeFilters[fKey].some(filterVal => itemValuesArray.includes(filterVal));
                            if (!match) return false;
                        }
                        return true;
                    });
                });
            });
        }
        function setupSorting() {
            document.querySelectorAll('#flexlist-engine .sort').forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    const sortKey = this.getAttribute('data-sort');
                    
                    if (!e.ctrlKey && !e.metaKey) {
                        const currentState = sortState[sortKey];
                        sortState = {}; // Reset for single-column sort
                        if (currentState === 'asc') {
                            sortState[sortKey] = 'desc';
                        } else {
                            sortState[sortKey] = 'asc';
                        }
                    } else { // Multi-column sort
                        if (!sortState[sortKey] || sortState[sortKey] === 'desc') {
                            sortState[sortKey] = 'asc';
                        } else {
                            delete sortState[sortKey];
                        }
                    }

                    performMasterSort();
                });
            });
        }
        function setupGlobalEventListeners() { document.addEventListener('click', e => { if (!e.target.closest('.header-content')) { document.querySelectorAll('.filter-popup.show').forEach(p => p.classList.remove('show')); } }); }
        function setupPaginationControls() {
            const paginationSelect = document.querySelector('#flexlist-engine .pagination-select');
            if (!paginationSelect) return;
            paginationSelect.addEventListener('change', function() {
                const newSizeStr = this.value; let newSize;
                if (String(newSizeStr).toLowerCase() === 'all') { newSize = list.items.length; } else { newSize = parseInt(newSizeStr, 10); }
                if (!isNaN(newSize) && newSize > 0) { list.page = newSize; list.show(1, newSize); list.update(); }
            });
        }
        setupGroupBy(); setupFilters(); setupSorting(); setupGlobalEventListeners(); setupPaginationControls(); performMasterSort();
EOD;
}


// ============================================================
//  4. スタイルエンジン (CSS)
// ============================================================

/**
 * @brief データテーブルのCSSを生成します。
 * @en Generates the CSS for the data table.
 * @return string CSSの<style>ブロック。 / The CSS <style> block.
 */
function plugin_flexlist_get_css()
{
    return <<<EOD
    <style>
      /* --- 全体コンテナ / Main Container --- */
      #flexlist-engine { border: 1px solid #dcdcdc; border-radius: 3px; background-color: #ffffff; box-sizing: border-box; }
      #flexlist-engine * { box-sizing: border-box; }

      /* --- 操作パネル / Controls Panel --- */
      #flexlist-engine .controls { padding: 15px; border-bottom: 1px solid #dcdcdc; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; background-color: #f1f1f1; }
      #flexlist-engine .search, 
      #flexlist-engine .group-by-select,
      #flexlist-engine .pagination-select { 
          padding: 8px; border: 1px solid #ccc; border-radius: 4px; height: 38px; 
      }
      #flexlist-engine .search { flex-grow: 1; min-width: 200px; }

      /* --- テーブル基本設定 / Basic Table Styles --- */
      #flexlist-engine .table-container { overflow-x: auto; }
      #flexlist-engine table { width: 100%; border-collapse: collapse; table-layout: fixed; }
      #flexlist-engine td { padding: 12px 15px; border-top: 1px solid #dcdcdc; text-align: left; vertical-align: top; word-wrap: break-word; word-break: break-all; }
      
      /* --- ヘッダー(th) / Table Header (th) --- */
      #flexlist-engine th { padding: 0; vertical-align: top; background-color: #f2f2f2; border-bottom: 1px solid #dcdcdc; text-align: left; font-weight: normal; }
      #flexlist-engine .header-content { position: relative; display: flex; align-items: stretch; justify-content: space-between; height: 45px; }
      #flexlist-engine .sort { flex-grow: 1; background: none; border: none; font-weight: inherit; cursor: pointer; padding: 0 15px; text-align: left; height: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #333; }
      #flexlist-engine .sort:hover { background-color: #e8e8e8; }
      #flexlist-engine .sort::after { content: '▲▼'; font-size: 0.8em; opacity: 0.5; margin-left: 5px; }
      #flexlist-engine .sort.asc::after, #flexlist-engine .sort.desc::after { opacity: 1; color: #0056b3; }
      #flexlist-engine .sort.asc::after { content: '▲'; }
      #flexlist-engine .sort.desc::after { content: '▼'; }
      
      /* --- フィルター機能 / Filter Feature --- */
      #flexlist-engine .filter-toggle { flex-shrink: 0; height: 100%; width: 30px; border: none; border-left: 1px solid #dcdcdc; background: none; cursor: pointer; font-size: 0.8em; color: #555; padding: 0; }
      #flexlist-engine .filter-toggle:hover { background-color: #e9ecef; }
      #flexlist-engine .filter-toggle.is-dummy { visibility: hidden; cursor: default; }
      #flexlist-engine .filter-popup { display: none; position: absolute; top: 100%; right: 0; z-index: 10; min-width: 200px; background-color: #fff; border: 1px solid #ccc; border-radius: 3px; box-shadow: 0 4px 10px rgba(0,0,0,0.15); padding: 10px; max-height: 300px; overflow-y: auto; text-align: left; }
      #flexlist-engine .filter-popup.show { display: block; }
      #flexlist-engine .filter-popup label { display: block; padding: 6px 8px; border-radius: 3px; cursor: pointer; white-space: nowrap; font-weight: normal; }
      #flexlist-engine .filter-popup label:hover { background-color: #f5f5f5; }
      #flexlist-engine .filter-popup input { margin-right: 8px; vertical-align: middle; }

      /* --- グループヘッダー / Group Header --- */
      #flexlist-engine .group-header-row td { border-bottom: 1px solid #ccc; padding: 10px 15px; font-weight: bold; }
      #flexlist-engine .group-header-level-1 td { background-color: #e0e0e0; color: #000; font-size: 1.1em; border-top: 2px solid #aaa; }
      #flexlist-engine .group-header-level-2 td { background-color: #f2f2f2; color: #333; padding-left: 30px; border-top: 1px solid #ccc; font-weight: normal; }
      /* --- ページネーション / Pagination --- */
      #flexlist-engine .pagination { padding: 15px; text-align: center; }
      #flexlist-engine .pagination li { display: inline-block; }
      #flexlist-engine .pagination a { display: block; padding: 5px 10px; text-decoration: none; border-radius: 3px; color: #333; }
      #flexlist-engine .pagination a:hover { background-color: #e9ecef; }
      #flexlist-engine .pagination .active a { font-weight: bold; background-color: #007bff; color: #fff; }

      /* --- 列の装飾クラス / Column Decoration Classes --- */
      #flexlist-engine .is-bold { font-weight: bold; }
      #flexlist-engine .is-italic { font-style: italic; }
      #flexlist-engine .is-strike { text-decoration: line-through; }
      #flexlist-engine .is-underline { text-decoration: underline; }
      #flexlist-engine .is-red { color: #ff0000; }
      #flexlist-engine .is-blue { color: #0000ff; }
      #flexlist-engine .align-center { text-align: center; }
      #flexlist-engine .align-right { text-align: right; }




    </style>
EOD;
}

// ============================================================
// ★★★ HELPER PLUGINS (Namespaced) ★★★
// ============================================================

/**
 * @brief #flexlist_config - 設定ブロックの開始マーカーを出力します。
 * @en #flexlist_config - Renders the start marker for the config block.
 * @details NOTE: This may appear as raw text on the data page due to PukiWiki's
 * rendering behavior, but it does not affect the main plugin's functionality.
 */
// #flexlist_config - Renders <!-- DATATABLE_CONFIG_START -->
function plugin_flexlist_config_convert() { return '<!-- DATATABLE_CONFIG_START -->'; }

// #flexlist_endconfig - Renders <!-- DATATABLE_CONFIG_END -->
function plugin_flexlist_endconfig_convert() { return '<!-- DATATABLE_CONFIG_END -->'; }

// #flexlist_data - Renders <!-- DATATABLE_DATA_START -->
function plugin_flexlist_data_convert() { return '<!-- DATATABLE_DATA_START -->'; }

// #flexlist_enddata - Renders <!-- DATATABLE_DATA_END -->
function plugin_flexlist_enddata_convert() { return '<!-- DATATABLE_DATA_END -->'; }

?>
