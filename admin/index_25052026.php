<?php
require_once '../core/init.php';

$allowed_roles = ['Superadmin', 'admin'];
restrict_access($allowed_roles);

global $db;
$currentUser = getCurrentUser();
$user_id     = $currentUser['id']   ?? null;
$user_role   = $currentUser['role'] ?? 'guest';
$pageTitle   = 'Dashboard';

require_once '../templates/header.php';

// ── Widgets ───────────────────────────────────────────────────────────────────
$widgets = $db->query(
    "SELECT dw.widget_key, dw.widget_name, dw.widget_type, dw.icon, dw.color,
            dw.required_roles, udp.size, udp.date_range, udp.refresh_interval, udp.custom_config
     FROM user_dashboard_preferences udp
     JOIN dashboard_widgets dw ON udp.widget_id = dw.id
     WHERE udp.user_id = ? AND dw.is_active = 1 AND udp.is_enabled = 1
     ORDER BY udp.position ASC",
    [$user_id]
)->results();

if (empty($widgets)) {
    $widgets = $db->query(
        "SELECT widget_key, widget_name, widget_type, icon, color, required_roles,
                'medium' as size, 'today' as date_range, 0 as refresh_interval, NULL as custom_config
         FROM dashboard_widgets WHERE is_active = 1 AND default_enabled = 1
         ORDER BY widget_category, sort_order ASC"
    )->results();
}

$filtered_widgets = array_filter($widgets, function($w) use ($user_role) {
    if ($user_role === 'Superadmin') return true;
    if (empty($w->required_roles))   return true;
    $roles = array_map('trim', explode(',', str_replace(['"','[',']'], '', $w->required_roles)));
    return in_array($user_role, $roles);
});

$csrfToken = $_SESSION['csrf_token'] ?? '';
?>

<div class="flex flex-col lg:flex-row min-h-[calc(100vh-4rem)]" x-data="dashboardApp()" x-init="init()">

    <!-- ── MAIN CONTENT ──────────────────────────────────────────────────────── -->
    <div class="flex-1 min-w-0">
        <div class="w-full px-4 sm:px-6 lg:px-8 py-6">

            <!-- Header -->
            <div class="flex flex-wrap justify-between items-center mb-6 gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">
                        Welcome, <?php echo htmlspecialchars($currentUser['display_name'] ?? 'Admin'); ?>!
                    </h1>
                    <p class="text-sm text-gray-500 mt-0.5"><?php echo date('l, d F Y'); ?> &bull; Live ERP Overview</p>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    <button @click="toggleAI()"
                            :class="aiOpen ? 'bg-purple-700 ring-2 ring-purple-400 ring-offset-1' : 'bg-purple-600 hover:bg-purple-700'"
                            class="relative flex items-center gap-2 px-4 py-2 text-white text-sm font-semibold rounded-lg shadow transition">
                        <i class="fas fa-robot"></i>
                        <span x-text="aiOpen ? 'Hide AI' : 'AI Suite'"></span>
                        <span x-show="unreadBadge"
                              class="absolute -top-1.5 -right-1.5 flex items-center justify-center w-5 h-5 bg-yellow-400 text-gray-900 text-xs font-bold rounded-full">!</span>
                    </button>
                    <a href="settings.php" class="flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-lg shadow transition">
                        <i class="fas fa-cog"></i> Customize
                    </a>
                </div>
            </div>

            <!-- Daily Brief Banner -->
            <div x-show="dailyBriefBanner && !aiOpen"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0 -translate-y-2"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 class="mb-5 bg-gradient-to-r from-purple-50 to-indigo-50 border border-purple-200 rounded-xl p-4 flex gap-3 items-start shadow-sm">
                <div class="flex-shrink-0 w-9 h-9 bg-purple-600 rounded-full flex items-center justify-center">
                    <i class="fas fa-robot text-white text-sm"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-xs font-bold text-purple-700 uppercase tracking-wide"><i class="fas fa-sun mr-1 text-yellow-400"></i>AI Daily Brief</span>
                        <button @click="dailyBriefBanner=false" class="text-gray-400 hover:text-gray-600 text-xs"><i class="fas fa-times"></i></button>
                    </div>
                    <div class="text-sm text-gray-700 leading-relaxed ai-md" x-html="dailyBriefHtml"></div>
                    <button @click="openWithTab('insights'); askInsight('daily_brief')" class="mt-2 text-xs text-purple-600 hover:text-purple-800 font-semibold">Open full AI Advisor →</button>
                </div>
            </div>

            <!-- Widgets -->
            <?php if (empty($filtered_widgets)): ?>
            <div class="bg-white rounded-xl shadow p-12 text-center">
                <i class="fas fa-th-large text-5xl text-gray-300 mb-4"></i>
                <h3 class="text-xl font-bold text-gray-700">No Widgets Configured</h3>
                <a href="settings.php" class="mt-4 inline-block px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-bold transition text-sm">
                    <i class="fas fa-cog mr-2"></i>Setup Dashboard
                </a>
            </div>
            <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-5">
                <?php foreach ($filtered_widgets as $widget):
                    $col = match($widget->size ?? 'medium') {
                        'small'=>'md:col-span-1','medium'=>'md:col-span-2',
                        'large'=>'md:col-span-3',default=>'md:col-span-4'
                    };
                    $wp = __DIR__.'/widgets/'.basename($widget->widget_key).'.php';
                    $date_range=$widget->date_range; $widget_title=$widget->widget_name;
                    $widget_icon=$widget->icon; $widget_color=$widget->color;
                    $widget_size=$widget->size; $refresh_interval=$widget->refresh_interval;
                    $custom_config=$widget->custom_config;
                ?>
                <div class="<?php echo $col; ?>">
                    <?php if(file_exists($wp)): include $wp;
                    else: ?><div class="bg-red-50 border border-red-200 text-red-600 p-4 rounded-xl text-sm">
                        <i class="fas fa-exclamation-triangle mr-2"></i>Widget missing: <code><?php echo htmlspecialchars(basename($wp)); ?></code></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

        </div>
    </div>


    <!-- ══════════════════════════════════════════════════════════════
         AI SUITE SIDEBAR  (3 tabs: Insights | Query DB | Agent)
         ══════════════════════════════════════════════════════════════ -->
    <div x-show="aiOpen"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 translate-x-8"
         x-transition:enter-end="opacity-100 translate-x-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 translate-x-0"
         x-transition:leave-end="opacity-0 translate-x-8"
         class="w-full lg:w-[460px] lg:min-w-[460px] border-l border-gray-200 bg-white flex flex-col shadow-2xl"
         style="height:calc(100vh - 4rem); position:sticky; top:4rem;">

        <!-- Sidebar Header -->
        <div class="bg-gradient-to-r from-purple-700 to-indigo-700 px-4 py-3 flex items-center justify-between flex-shrink-0">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 bg-white/20 rounded-full flex items-center justify-center">
                    <i class="fas fa-robot text-white text-sm"></i>
                </div>
                <div>
                    <h2 class="text-white font-bold text-sm">AI ERP Suite</h2>
                    <p class="text-purple-200 text-xs">Groq · LLaMA 3.3 70B · Full DB</p>
                </div>
            </div>
            <button @click="toggleAI()" class="text-white/70 hover:text-white"><i class="fas fa-times text-lg"></i></button>
        </div>

        <!-- Tab Bar -->
        <div class="flex border-b border-gray-200 bg-gray-50 flex-shrink-0">
            <button @click="activeTab='insights'"
                    :class="activeTab==='insights' ? 'border-b-2 border-purple-600 text-purple-700 bg-white font-bold' : 'text-gray-500 hover:text-gray-700'"
                    class="flex-1 py-2.5 text-xs uppercase tracking-wide transition">
                <i class="fas fa-chart-pie mr-1"></i>Insights
            </button>
            <button @click="activeTab='query'"
                    :class="activeTab==='query' ? 'border-b-2 border-blue-600 text-blue-700 bg-white font-bold' : 'text-gray-500 hover:text-gray-700'"
                    class="flex-1 py-2.5 text-xs uppercase tracking-wide transition">
                <i class="fas fa-database mr-1"></i>Query DB
            </button>
            <button @click="activeTab='agent'"
                    :class="activeTab==='agent' ? 'border-b-2 border-green-600 text-green-700 bg-white font-bold' : 'text-gray-500 hover:text-gray-700'"
                    class="flex-1 py-2.5 text-xs uppercase tracking-wide transition">
                <i class="fas fa-magic mr-1"></i>Agent
                <span class="ml-1 bg-green-600 text-white text-xs px-1 rounded-full">NEW</span>
            </button>
        </div>


        <!-- ══════════════════
             TAB 1: INSIGHTS
             ══════════════════ -->
        <div x-show="activeTab==='insights'" class="flex flex-col flex-1 overflow-hidden">
            <div class="px-3 py-2 border-b border-gray-100 bg-gray-50 flex-shrink-0">
                <div class="flex flex-wrap gap-1.5">
                    <template x-for="chip in insightChips" :key="chip.action">
                        <button @click="askInsight(chip.action)"
                                :class="insightAction===chip.action ? 'bg-purple-600 text-white border-purple-600':'bg-white text-gray-700 border-gray-300 hover:border-purple-400'"
                                class="flex items-center gap-1 px-2.5 py-1 border rounded-full text-xs font-medium transition"
                                x-html="chip.label"></button>
                    </template>
                </div>
            </div>
            <div class="flex-1 overflow-y-auto p-4 space-y-3" id="insightsScroll">
                <div x-show="!insightLoading && !insightResponse && !insightError" class="text-center py-10">
                    <i class="fas fa-robot text-purple-300 text-4xl mb-3"></i>
                    <p class="text-gray-500 text-sm font-medium">Live ERP Insights</p>
                    <p class="text-gray-400 text-xs mt-1">Click a chip above or type a question below</p>
                </div>
                <div x-show="insightLoading" class="flex flex-col items-center py-8 gap-3">
                    <div class="flex gap-1"><span class="dot bg-purple-500"></span><span class="dot bg-purple-400" style="animation-delay:.15s"></span><span class="dot bg-purple-300" style="animation-delay:.3s"></span></div>
                    <p class="text-xs text-gray-500" x-text="insightLoadingMsg"></p>
                </div>
                <div x-show="insightError && !insightLoading" class="bg-red-50 border border-red-200 rounded-xl p-3 text-sm text-red-700">
                    <i class="fas fa-exclamation-circle mr-1"></i><span x-text="insightError"></span>
                    <button @click="askInsight(insightAction)" class="ml-2 text-xs underline">Retry</button>
                </div>
                <div x-show="insightResponse && !insightLoading">
                    <div class="text-xs font-bold text-purple-700 uppercase tracking-wide mb-2" x-text="insightLabel"></div>
                    <div class="bg-gradient-to-br from-purple-50 to-white border border-purple-100 rounded-xl p-4 shadow-sm">
                        <div class="ai-md text-gray-700 text-sm leading-relaxed" x-html="insightResponse"></div>
                    </div>
                    <div class="flex gap-2 mt-2">
                        <button @click="copyTxt(insightResponse)" class="text-xs text-gray-500 hover:text-gray-700 border border-gray-200 rounded px-2 py-1"><i class="fas fa-copy mr-1"></i>Copy</button>
                        <button @click="askInsight(insightAction)" class="text-xs text-gray-500 hover:text-gray-700 border border-gray-200 rounded px-2 py-1"><i class="fas fa-sync-alt mr-1"></i>Refresh</button>
                        <span x-show="copied" class="text-xs text-green-600 font-medium mt-0.5"><i class="fas fa-check mr-1"></i>Copied</span>
                    </div>
                </div>
            </div>
            <div class="border-t border-gray-200 p-3 flex-shrink-0">
                <div class="flex gap-2">
                    <input x-model="insightQ" @keydown.enter.prevent="if(insightQ.trim()) askInsightCustom()"
                           type="text" placeholder="Ask about your ERP data…"
                           class="flex-1 text-sm px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-400" :disabled="insightLoading">
                    <button @click="askInsightCustom()" :disabled="insightLoading||!insightQ.trim()"
                            class="px-3 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition disabled:opacity-40">
                        <i class="fas fa-paper-plane text-sm"></i></button>
                </div>
            </div>
        </div>


        <!-- ══════════════════
             TAB 2: QUERY DB
             ══════════════════ -->
        <div x-show="activeTab==='query'" class="flex flex-col flex-1 overflow-hidden">
            <div class="px-3 py-2.5 border-b border-gray-100 bg-blue-50 flex-shrink-0">
                <p class="text-xs font-bold text-blue-700 mb-1.5 uppercase tracking-wide"><i class="fas fa-lightbulb mr-1 text-yellow-500"></i>Example queries</p>
                <div class="flex flex-wrap gap-1.5">
                    <template x-for="ex in qExamples" :key="ex">
                        <button @click="dbQ=ex; runDbQuery()"
                                class="px-2 py-1 bg-white border border-blue-200 text-blue-700 text-xs rounded-full hover:bg-blue-50 transition" x-text="ex"></button>
                    </template>
                </div>
            </div>
            <div class="px-3 pt-3 pb-2 border-b border-gray-100 flex-shrink-0">
                <div class="flex gap-2">
                    <input x-model="dbQ" @keydown.enter.prevent="if(dbQ.trim()) runDbQuery()"
                           type="text" placeholder="e.g. Show all payments made today…"
                           class="flex-1 text-sm px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-400" :disabled="dbLoading">
                    <button @click="runDbQuery()" :disabled="dbLoading||!dbQ.trim()"
                            class="px-3 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition disabled:opacity-40">
                        <i :class="dbLoading?'fas fa-spinner fa-spin':'fas fa-search'" class="text-sm"></i></button>
                </div>
                <p class="text-xs text-gray-400 mt-1"><i class="fas fa-database mr-1 text-blue-400"></i>Read-only · AI writes & runs SQL · Up to 200 rows</p>
            </div>
            <div class="flex-1 overflow-y-auto" id="queryScroll">
                <div x-show="!dbLoading&&!dbResponse&&!dbError" class="text-center py-10 px-4">
                    <i class="fas fa-database text-blue-200 text-4xl mb-3"></i>
                    <p class="text-gray-500 text-sm font-medium">Ask Anything About Your Database</p>
                    <p class="text-gray-400 text-xs mt-1 max-w-xs mx-auto">Plain English → SQL → Executed → Summary</p>
                </div>
                <div x-show="dbLoading" class="flex flex-col items-center py-8 gap-3">
                    <div class="flex gap-1"><span class="dot bg-blue-500"></span><span class="dot bg-blue-400" style="animation-delay:.15s"></span><span class="dot bg-blue-300" style="animation-delay:.3s"></span></div>
                    <p class="text-xs text-gray-500" x-text="dbLoadingMsg"></p>
                </div>
                <div x-show="dbError&&!dbLoading" class="m-3 bg-red-50 border border-red-200 rounded-xl p-3 text-sm text-red-700">
                    <i class="fas fa-exclamation-circle mr-1"></i><span x-text="dbError"></span>
                    <button @click="runDbQuery()" class="ml-2 text-xs underline">Retry</button>
                </div>
                <div x-show="dbResponse&&!dbLoading" class="p-3 space-y-3">
                    <div class="bg-blue-50 border border-blue-200 rounded-xl p-3">
                        <div class="flex items-center gap-2 mb-1.5">
                            <i class="fas fa-robot text-blue-600 text-xs"></i>
                            <span class="text-xs font-bold text-blue-700 uppercase tracking-wide">Summary</span>
                            <span class="ml-auto text-xs text-blue-400" x-text="dbRowCount + ' rows · ' + dbTime"></span>
                        </div>
                        <p class="text-sm text-gray-700" x-text="dbResponse"></p>
                    </div>
                    <div class="border border-gray-200 rounded-xl overflow-hidden">
                        <button @click="showSql=!showSql" class="w-full flex items-center justify-between px-3 py-2 bg-gray-50 text-xs font-semibold text-gray-600 hover:bg-gray-100">
                            <span><i class="fas fa-code mr-1.5"></i>Generated SQL</span>
                            <i :class="showSql?'fa-chevron-up':'fa-chevron-down'" class="fas text-gray-400"></i>
                        </button>
                        <div x-show="showSql" class="bg-gray-900 p-3 overflow-x-auto">
                            <pre class="text-green-400 text-xs whitespace-pre-wrap font-mono" x-text="dbSql"></pre>
                            <button @click="copyTxt(dbSql)" class="mt-2 text-xs text-gray-400 hover:text-white"><i class="fas fa-copy mr-1"></i>Copy SQL</button>
                        </div>
                    </div>
                    <div x-show="dbRows.length>0" class="border border-gray-200 rounded-xl overflow-hidden">
                        <div class="flex items-center justify-between px-3 py-2 bg-gray-50 border-b border-gray-200">
                            <span class="text-xs font-semibold text-gray-600"><i class="fas fa-table mr-1.5"></i>Results <span class="text-gray-400 font-normal" x-text="'('+dbRows.length+' rows)'"></span></span>
                            <button @click="exportCsv()" class="text-xs text-blue-600 hover:text-blue-800 font-semibold"><i class="fas fa-download mr-1"></i>CSV</button>
                        </div>
                        <div class="overflow-x-auto" style="max-height:340px;overflow-y:auto;">
                            <table class="w-full text-xs">
                                <thead class="bg-gray-100 sticky top-0">
                                    <tr><template x-for="col in dbColumns" :key="col">
                                        <th class="px-3 py-2 text-left font-semibold text-gray-600 whitespace-nowrap border-r border-gray-200 last:border-r-0"
                                            x-text="col.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase())"></th>
                                    </template></tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <template x-for="(row,i) in dbRows" :key="i">
                                        <tr :class="i%2===0?'bg-white':'bg-gray-50'" class="hover:bg-blue-50 transition">
                                            <template x-for="col in dbColumns" :key="col">
                                                <td class="px-3 py-1.5 text-gray-700 whitespace-nowrap border-r border-gray-100 last:border-r-0 max-w-[140px] truncate"
                                                    :title="String(row[col]??'')" x-text="row[col]!==null&&row[col]!==undefined?row[col]:'—'"></td>
                                            </template>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>


        <!-- ══════════════════════════════════════
             TAB 3: AGENT  (conversational / write)
             ══════════════════════════════════════ -->
        <div x-show="activeTab==='agent'" class="flex flex-col flex-1 overflow-hidden">

            <!-- Agent capability chips -->
            <div class="px-3 py-2.5 border-b border-gray-100 bg-green-50 flex-shrink-0">
                <div class="flex items-center justify-between mb-1.5">
                    <p class="text-xs font-bold text-green-700 uppercase tracking-wide"><i class="fas fa-magic mr-1"></i>I can do this for you</p>
                    <button @click="resetAgent()" x-show="agentMessages.length > 0"
                            class="text-xs text-red-500 hover:text-red-700 transition"><i class="fas fa-trash-alt mr-1"></i>Clear</button>
                </div>
                <div class="flex flex-wrap gap-1.5">
                    <template x-for="cap in agentCapabilities" :key="cap.label">
                        <button @click="startAgentAction(cap.msg)"
                                class="flex items-center gap-1 px-2.5 py-1 bg-white border border-green-200 text-green-700 text-xs font-medium rounded-full hover:bg-green-100 hover:border-green-400 transition"
                                x-html="cap.label"></button>
                    </template>
                </div>
            </div>

            <!-- Chat messages -->
            <div class="flex-1 overflow-y-auto p-3 space-y-3" id="agentChat" x-ref="agentChat">

                <!-- Welcome (no messages yet) -->
                <div x-show="agentMessages.length === 0 && !agentLoading" class="text-center py-8 px-3">
                    <div class="w-14 h-14 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-3">
                        <i class="fas fa-magic text-green-600 text-xl"></i>
                    </div>
                    <h3 class="font-bold text-gray-700 text-sm">ERP Agent</h3>
                    <p class="text-gray-500 text-xs mt-1 max-w-xs mx-auto">I can create records, update statuses, and modify your ERP — just ask in plain English.</p>
                    <div class="mt-4 grid grid-cols-1 gap-2 text-left max-w-xs mx-auto">
                        <div class="flex items-start gap-2 bg-green-50 rounded-lg px-3 py-2">
                            <i class="fas fa-check-circle text-green-500 text-xs mt-0.5"></i>
                            <p class="text-xs text-gray-600">"Add role Bank Transaction Approver"</p>
                        </div>
                        <div class="flex items-start gap-2 bg-green-50 rounded-lg px-3 py-2">
                            <i class="fas fa-check-circle text-green-500 text-xs mt-0.5"></i>
                            <p class="text-xs text-gray-600">"Create a purchase order for wheat from Canada"</p>
                        </div>
                        <div class="flex items-start gap-2 bg-green-50 rounded-lg px-3 py-2">
                            <i class="fas fa-check-circle text-green-500 text-xs mt-0.5"></i>
                            <p class="text-xs text-gray-600">"Create a credit order for customer Nibir"</p>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <template x-for="(msg, idx) in agentMessages" :key="idx">
                    <div :class="msg.role==='user' ? 'flex justify-end' : 'flex justify-start'">
                        <!-- AI avatar -->
                        <div x-show="msg.role==='assistant'" class="flex-shrink-0 w-7 h-7 bg-green-100 rounded-full flex items-center justify-center mr-2 mt-1 self-start">
                            <i :class="msg.executed ? 'fas fa-check text-green-600' : 'fas fa-robot text-green-600'" class="text-xs"></i>
                        </div>
                        <div :class="msg.role==='user'
                                  ? 'bg-indigo-600 text-white rounded-2xl rounded-tr-sm px-4 py-2.5 max-w-[82%]'
                                  : msg.executed
                                    ? 'bg-green-50 border border-green-200 rounded-2xl rounded-tl-sm px-4 py-2.5 max-w-[85%]'
                                    : 'bg-white border border-gray-200 rounded-2xl rounded-tl-sm px-4 py-2.5 max-w-[85%] shadow-sm'">
                            <div :class="msg.role==='user' ? 'text-white text-sm' : 'text-gray-800 text-sm ai-md leading-relaxed'"
                                 x-html="msg.role==='user' ? escHtml(msg.content) : md(msg.content)"></div>
                            <div x-show="msg.executed" class="mt-1.5 flex items-center gap-1.5">
                                <span class="text-xs text-green-600 font-semibold"><i class="fas fa-check-circle mr-1"></i>Action Executed</span>
                            </div>
                            <div class="text-xs mt-1" :class="msg.role==='user'?'text-indigo-200':'text-gray-400'" x-text="msg.time"></div>
                        </div>
                    </div>
                </template>

                <!-- Typing indicator -->
                <div x-show="agentLoading" class="flex justify-start">
                    <div class="w-7 h-7 bg-green-100 rounded-full flex items-center justify-center mr-2 flex-shrink-0">
                        <i class="fas fa-robot text-green-600 text-xs"></i>
                    </div>
                    <div class="bg-white border border-gray-200 rounded-2xl rounded-tl-sm px-4 py-3 shadow-sm">
                        <div class="flex gap-1.5 items-center">
                            <span class="dot bg-green-500"></span>
                            <span class="dot bg-green-400" style="animation-delay:.2s"></span>
                            <span class="dot bg-green-300" style="animation-delay:.4s"></span>
                            <span class="text-xs text-gray-400 ml-1" x-text="agentLoadingMsg"></span>
                        </div>
                    </div>
                </div>
            </div><!-- /chat messages -->

            <!-- Chat input -->
            <div class="border-t border-gray-200 p-3 flex-shrink-0 bg-white">
                <div class="flex gap-2">
                    <input x-model="agentInput"
                           @keydown.enter.prevent="if(agentInput.trim()) sendAgentMessage()"
                           type="text"
                           placeholder="Tell me what to do…"
                           class="flex-1 text-sm px-3 py-2.5 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-green-400"
                           :disabled="agentLoading">
                    <button @click="sendAgentMessage()" :disabled="agentLoading||!agentInput.trim()"
                            class="px-4 py-2.5 bg-green-600 text-white rounded-xl hover:bg-green-700 transition disabled:opacity-40">
                        <i :class="agentLoading?'fas fa-spinner fa-spin':'fas fa-paper-plane'" class="text-sm"></i>
                    </button>
                </div>
                <div class="flex items-center justify-between mt-1.5">
                    <p class="text-xs text-gray-400"><i class="fas fa-lock text-green-500 mr-1"></i>Safe pre-defined actions &bull; Always confirms before writing</p>
                    <span class="text-xs text-gray-400" x-show="agentProvider">
                        via <span class="font-medium" :class="agentProvider==='Groq'?'text-orange-500':agentProvider==='Gemini'?'text-blue-500':'text-purple-500'" x-text="agentProvider"></span>
                    </span>
                </div>
            </div>

        </div><!-- /agent tab -->

    </div><!-- /AI Sidebar -->

</div><!-- /flex layout -->


<!-- ══════════════════════════════════════════════════════════
     STYLES
     ══════════════════════════════════════════════════════════ -->
<style>
.ai-md h3 { font-size:.875rem; font-weight:700; color:#166534; margin:.75rem 0 .375rem; }
.ai-md h3:first-child { margin-top:0; }
.ai-md ul { list-style:disc; padding-left:1.25rem; margin:.4rem 0; }
.ai-md li { margin:.2rem 0; font-size:.8125rem; }
.ai-md strong { font-weight:700; color:#111827; }
.ai-md p { margin:.375rem 0; font-size:.8125rem; }
.ai-md hr { border-color:#d1fae5; margin:.6rem 0; }
.ai-md code { background:#f0fdf4; color:#15803d; padding:.1rem .3rem; border-radius:.25rem; font-size:.75rem; }
.dot { display:inline-block; width:8px; height:8px; border-radius:50%; animation:dotBounce .8s infinite; }
@keyframes dotBounce { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-6px)} }
</style>


<!-- ══════════════════════════════════════════════════════════
     ALPINE.JS APP
     ══════════════════════════════════════════════════════════ -->
<script>
const csrfToken = "<?php echo htmlspecialchars($csrfToken, ENT_QUOTES); ?>";

function dashboardApp() {
    return {
        // ── Panel
        aiOpen: false,
        activeTab: 'insights',
        unreadBadge: false,
        copied: false,

        // ── Insights tab
        insightLoading: false,
        insightResponse: '',
        insightError: '',
        insightAction: '',
        insightLabel: '',
        insightQ: '',
        insightLoadingMsg: 'Analyzing ERP data…',
        dailyBriefBanner: false,
        dailyBriefHtml: '',

        insightChips: [
            { action:'daily_brief',    label:'<i class="fas fa-sun text-yellow-400 mr-1"></i>Daily Brief' },
            { action:'cash_flow',      label:'<i class="fas fa-coins text-green-500 mr-1"></i>Cash Flow' },
            { action:'credit_risk',    label:'<i class="fas fa-shield-alt text-red-500 mr-1"></i>Credit Risk' },
            { action:'operations',     label:'<i class="fas fa-industry text-orange-500 mr-1"></i>Operations' },
            { action:'sales_analysis', label:'<i class="fas fa-chart-line text-blue-500 mr-1"></i>Sales' },
        ],
        insightLabelMap: {
            daily_brief:'🌅 Daily Brief', cash_flow:'💰 Cash Flow', credit_risk:'🛡️ Credit Risk',
            operations:'🏭 Operations', sales_analysis:'📈 Sales Analysis', custom:'💬 Custom',
        },
        insightLoadingMsgs: ['Analyzing ERP data…','Reading sales & orders…','Checking receivables…','Calculating insights…','Almost ready…'],

        // ── Query DB tab
        dbLoading: false,
        dbQ: '',
        dbResponse: '',
        dbError: '',
        dbRows: [], dbColumns: [], dbSql: '', dbRowCount: 0, dbTime: '',
        showSql: false,
        dbLoadingMsg: 'Translating to SQL…',
        dbLoadingMsgs: ['Translating to SQL…','Writing query…','Executing against database…','Summarizing results…'],

        qExamples: [
            'Show all transactions made today',
            'List payments collected this month',
            'Which customers are over their credit limit?',
            'Show overdue credit orders with customer names',
            'List approved expenses this month',
            'Show pending purchase orders',
        ],

        // ── Agent tab
        agentLoading: false,
        agentInput: '',
        agentMessages: [],
        agentLoadingMsg: 'Thinking…',
        agentProvider: '',
        agentLoadingMsgs: ['Thinking…','Understanding your request…','Preparing to act…','Almost ready…'],

        agentCapabilities: [
            { label:'<i class="fas fa-user-tag text-indigo-500 mr-1"></i>Add User Role',      msg:'I want to add a new user role' },
            { label:'<i class="fas fa-user-plus text-blue-500 mr-1"></i>Create User',          msg:'Create a new user account' },
            { label:'<i class="fas fa-file-invoice text-purple-500 mr-1"></i>Purchase Order',  msg:'Create a new purchase order for wheat' },
            { label:'<i class="fas fa-shopping-cart text-green-500 mr-1"></i>Credit Order',    msg:'Create a new credit order' },
            { label:'<i class="fas fa-exchange-alt text-orange-500 mr-1"></i>Update Order Status', msg:'Update the status of a credit order' },
            { label:'<i class="fas fa-user-circle text-teal-500 mr-1"></i>Create Customer',    msg:'Add a new customer to the system' },
            { label:'<i class="fas fa-receipt text-red-500 mr-1"></i>Record Expense',          msg:'Record a new expense voucher' },
        ],

        init() {
            setTimeout(() => this.fetchBanner(), 900);
        },

        toggleAI() {
            this.aiOpen = !this.aiOpen;
            this.unreadBadge = false;
            if (this.aiOpen && this.activeTab === 'insights' && !this.insightResponse) {
                this.askInsight('daily_brief');
            }
        },

        openWithTab(tab) {
            this.aiOpen = true;
            this.activeTab = tab;
            this.unreadBadge = false;
        },

        // ── INSIGHTS ────────────────────────────────────────────────────────
        async askInsight(action) {
            this.insightAction = action;
            this.insightLabel  = this.insightLabelMap[action] || 'AI Response';
            this.insightLoading = true;
            this.insightResponse = ''; this.insightError = '';
            let i = 0, iv = setInterval(() => { this.insightLoadingMsg = this.insightLoadingMsgs[i++%this.insightLoadingMsgs.length]; }, 1200);
            try {
                const d = await this.callAdvisor(action, '');
                clearInterval(iv);
                if (d.success) { this.insightResponse = this.md(d.response); }
                else           { this.insightError = d.error || 'Error'; }
            } catch { clearInterval(iv); this.insightError = 'Network error.'; }
            this.insightLoading = false;
        },

        async askInsightCustom() {
            const q = this.insightQ.trim(); if (!q) return;
            this.insightAction = 'custom'; this.insightLabel = '💬 Custom';
            this.insightLoading = true; this.insightResponse = ''; this.insightError = '';
            try {
                const d = await this.callAdvisor('custom', q);
                if (d.success) { this.insightResponse = this.md(d.response); this.insightQ = ''; }
                else           { this.insightError = d.error; }
            } catch { this.insightError = 'Network error.'; }
            this.insightLoading = false;
        },

        async fetchBanner() {
            try {
                const d = await this.callAdvisor('daily_brief', '');
                if (d.success) {
                    this.dailyBriefHtml = this.md(d.response.substring(0, 500) + '…');
                    this.dailyBriefBanner = true;
                    this.unreadBadge = true;
                }
            } catch {}
        },

        async callAdvisor(action, question) {
            const r = await fetch('ai_dashboard_advisor.php', {
                method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrfToken},
                body: JSON.stringify({ action, question })
            });
            return r.json();
        },

        // ── QUERY DB ─────────────────────────────────────────────────────────
        async runDbQuery() {
            const q = this.dbQ.trim(); if (!q) return;
            this.dbLoading = true; this.dbResponse=''; this.dbError=''; this.dbRows=[]; this.dbColumns=[]; this.dbSql=''; this.showSql=false;
            let i=0, iv=setInterval(()=>{ this.dbLoadingMsg=this.dbLoadingMsgs[i++%this.dbLoadingMsgs.length]; },1400);
            const t0=Date.now();
            try {
                const r = await fetch('ai_dashboard_advisor.php', {
                    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrfToken},
                    body: JSON.stringify({ action:'db_query', question:q })
                });
                const d = await r.json(); clearInterval(iv);
                if (d.success) {
                    this.dbResponse=d.response; this.dbRows=d.rows||[]; this.dbColumns=d.columns||[];
                    this.dbSql=d.sql||''; this.dbRowCount=d.row_count||0;
                    this.dbTime=((Date.now()-t0)/1000).toFixed(1)+'s';
                } else { this.dbError=d.error||'Error'; }
            } catch { clearInterval(iv); this.dbError='Network error.'; }
            this.dbLoading=false;
        },

        exportCsv() {
            if (!this.dbRows.length) return;
            const header = this.dbColumns.join(',');
            const rows = this.dbRows.map(r => this.dbColumns.map(c => {
                const v=r[c]??'';
                return (String(v).includes(',')||String(v).includes('"')||String(v).includes('\n'))
                    ? '"'+String(v).replace(/"/g,'""')+'"' : v;
            }).join(','));
            const blob=new Blob(['\uFEFF'+[header,...rows].join('\n')],{type:'text/csv;charset=utf-8;'});
            const a=document.createElement('a'); a.href=URL.createObjectURL(blob);
            a.download='query_'+new Date().toISOString().slice(0,10)+'.csv'; a.click();
        },

        // ── AGENT ────────────────────────────────────────────────────────────
        startAgentAction(msg) {
            this.activeTab = 'agent';
            this.agentInput = msg;
            this.sendAgentMessage();
        },

        async sendAgentMessage() {
            const msg = this.agentInput.trim(); if (!msg) return;
            this.agentInput = '';
            this.agentMessages.push({ role:'user', content: msg, time: this.timeNow() });
            this.agentLoading = true;
            this.scrollChat();
            let i=0, iv=setInterval(()=>{ this.agentLoadingMsg=this.agentLoadingMsgs[i++%this.agentLoadingMsgs.length]; },1500);
            try {
                const r = await fetch('ai_agent.php', {
                    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrfToken},
                    body: JSON.stringify({ sub_action:'message', message: msg })
                });
                const d = await r.json(); clearInterval(iv);
                if (d.success) {
                    this.agentProvider = d.provider || '';
                    this.agentMessages.push({
                        role: 'assistant',
                        content: d.message,
                        executed: d.executed || false,
                        time: this.timeNow(),
                    });
                } else {
                    this.agentMessages.push({ role:'assistant', content:'⚠️ Error: '+(d.error||'Unknown error'), time:this.timeNow() });
                }
            } catch(e) {
                clearInterval(iv);
                this.agentMessages.push({ role:'assistant', content:'⚠️ Network error. Please try again.', time:this.timeNow() });
            }
            this.agentLoading = false;
            this.$nextTick(() => this.scrollChat());
        },

        async resetAgent() {
            if (!confirm('Clear conversation?')) return;
            await fetch('ai_agent.php', {
                method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrfToken},
                body: JSON.stringify({ sub_action:'reset' })
            });
            this.agentMessages = [];
        },

        scrollChat() {
            this.$nextTick(() => {
                const el = document.getElementById('agentChat');
                if (el) el.scrollTop = el.scrollHeight;
            });
        },

        // ── UTILITIES ────────────────────────────────────────────────────────
        timeNow() {
            return new Date().toLocaleTimeString('en-BD', { hour:'2-digit', minute:'2-digit' });
        },

        copyTxt(text) {
            navigator.clipboard.writeText(text.replace(/<[^>]*>/g,''))
                .then(() => { this.copied=true; setTimeout(()=>this.copied=false,2000); });
        },

        escHtml(t) {
            return String(t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        },

        md(t) {
            return String(t)
                .replace(/^### (.+)$/gm,'<h3>$1</h3>')
                .replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>')
                .replace(/`([^`]+)`/g,'<code>$1</code>')
                .replace(/^[*\-] (.+)$/gm,'<li>$1</li>')
                .replace(/(<li>.+<\/li>\n?)+/gs, m=>'<ul>'+m+'</ul>')
                .replace(/^\d+\. (.+)$/gm,'<li>$1</li>')
                .replace(/^---$/gm,'<hr>')
                .replace(/✅/g,'<span class="text-green-600">✅</span>')
                .replace(/⚠️/g,'<span class="text-yellow-500">⚠️</span>')
                .replace(/\n\n+/g,'</p><p>').replace(/\n/g,'<br>');
        },
    }
}
</script>

<?php require_once '../templates/footer.php'; ?>