<?php
// Footer line (templates/blocks/copyright.zetem). Uses the core htmltext module's class.
function register_copyright_module() {
    global $kernel;
    $kernel->registerModule(new htmltextModule(__DIR__, 'copyright', 'copyright.zetem', ''));
}
