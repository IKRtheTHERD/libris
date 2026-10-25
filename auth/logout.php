<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_post();
require_valid_csrf();
sign_out();
redirect_to(app_url());
