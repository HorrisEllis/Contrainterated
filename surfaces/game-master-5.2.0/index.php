<?php
/**
 * index.php — Game Master frontend entry point
 * Serves the terminal UI using shared header/footer includes.
 */

$pageTitle = 'TERMINAL v2.1 | AI Game Master';
$bodyClass = '';
$scripts   = [];   // app.js is loaded below as an ES module, not by the footer

include __DIR__ . '/public/templates/header.php';
?>

<div class="monitor">
    <div class="screen">

        <!-- ── Connection-error overlay ─────────────────── -->
        <div class="error-overlay hidden" id="error-overlay">
            <div class="error-icon">⚠</div>
            <div class="error-title">CONNECTION LOST</div>
            <div class="error-message">
                Unable to establish link with Game Master server.<br>
                The system may be offline or unreachable.
            </div>
            <div class="error-details" id="error-details">
                ERROR: API_UNREACHABLE<br>
                ENDPOINT: ./api<br>
                STATUS: TIMEOUT
            </div>
            <button class="btn btn-outline-red" id="error-retry-btn">[ RETRY CONNECTION ]</button>
        </div>

        <div class="content">

            <!-- ── Start screen ──────────────────────────── -->
            <div id="start-screen" class="start-screen">
                <div class="logo text-glow letter-wide">TERMINAL</div>
                <div class="subtitle">AI Game Master v2.1</div>

                <div class="start-form">
                    <div class="field">
                        <label>DESIGNATION:</label>
                        <input type="text" id="player-name"
                               placeholder="Enter your name…" maxlength="30">
                    </div>

                    <div class="field">
                        <label>MISSION PROFILE:</label>
                        <select id="world-template">
                            <option value="">Loading…</option>
                        </select>
                    </div>

                    <button class="btn btn-block" id="start-btn">
                        [ INITIALIZE MISSION ]
                    </button>
                </div>
            </div>

            <!-- ── Game screen ───────────────────────────── -->
            <div id="game-screen" class="hidden">

                <div class="game-header">
                    <div class="game-title">TERMINAL v2.1</div>
                    <div class="status connected" id="status">CONNECTED</div>
                </div>

                <div class="game-area">

                    <!-- processing overlay -->
                    <div class="processing-overlay" id="processing-overlay">
                        <div class="spinner spinner-lg"></div>
                        <div class="processing-text">PROCESSING</div>
                        <div class="processing-subtext">Contacting AI Game Master…</div>
                    </div>

                    <!-- scroll indicators -->
                    <button class="scroll-indicator scroll-top"    id="scroll-top">▲ SCROLL TO TOP ▲</button>
                    <button class="scroll-indicator scroll-bottom" id="scroll-bottom">▼ NEW CONTENT ▼</button>

                    <!-- narrative log -->
                    <div class="output" id="output"></div>

                    <!-- HUD -->
                    <div class="stats-panel" id="stats-panel">
                        <div class="stat">
                            <span class="stat-label">LOCATION:</span>
                            <span class="stat-value" id="stat-zone">---</span>
                        </div>
                        <div class="stat">
                            <span class="stat-label">TURN:</span>
                            <span class="stat-value" id="stat-turn">0</span>
                        </div>
                        <div class="stat">
                            <span class="stat-label">PLAYER:</span>
                            <span class="stat-value" id="stat-player">---</span>
                        </div>
                    </div>

                    <!-- suggested actions -->
                    <div class="action-chips" id="action-chips"></div>

                    <!-- command input -->
                    <div class="input-row">
                        <span class="input-prompt">CMD</span>
                        <input type="text" class="input-text" id="command-input"
                               placeholder="Enter command…" autocomplete="off">
                        <button class="btn btn-primary" id="submit-btn">EXEC</button>
                    </div>

                </div><!-- /.game-area -->
            </div><!-- /#game-screen -->

        </div><!-- /.content -->
    </div><!-- /.screen -->
</div><!-- /.monitor -->

<?php
// app.js is an ES module: it imports the client gates and the vendored
// SISO core (siso/js/code/core), so it is loaded with a module tag only.
?>
<script type="module" src="/public/js/app.js"></script>

<?php include __DIR__ . '/public/templates/footer.php'; ?>
