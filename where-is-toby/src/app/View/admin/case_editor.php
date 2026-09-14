<?php use App\Core\View; ?>
<div data-admin-page="case-editor"
     data-is-new="<?= $isNew ? '1' : '0' ?>"
     data-ai="<?= $aiAvailable ? '1' : '0' ?>">
    <div class="toolbar">
        <button class="btn btn--primary" data-action="editor-save">Entwurf speichern</button>
        <button class="btn" data-action="editor-publish">Pruefen und veroeffentlichen</button>
        <button class="btn" data-action="editor-validate">Konsistenzpruefung</button>
        <button class="btn" data-action="editor-preview">Vorschau / Testmodus</button>
        <button class="btn" data-action="editor-reset-test">Testfortschritt zuruecksetzen</button>
        <span class="spacer"></span>
        <span class="hint" data-role="editor-status">Bereit.</span>
    </div>

    <div class="alert alert--warn" data-role="validation-box" hidden></div>

    <div class="editor-layout">
        <nav class="editor-nav" data-role="editor-nav"></nav>
        <div data-role="editor-body"></div>
    </div>

    <script type="application/json" data-role="case-data"><?= View::js($caseData) ?></script>
    <script type="application/json" data-role="validation"><?= View::js($validation) ?></script>
    <script type="application/json" data-role="versions"><?= View::js($versions) ?></script>
    <script type="application/json" data-role="media-library"><?= View::js($mediaLibrary) ?></script>
    <script type="application/json" data-role="audio-tracks"><?= View::js($audioTracks) ?></script>
</div>
