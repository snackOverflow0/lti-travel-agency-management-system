<?php
// Local development/test config template. Copy to config.dev.php / config.test.php (both are git-ignored and excluded from release ZIPs).
return ['env'=>'development','timezone'=>'America/New_York',
  'db'=>['host'=>'127.0.0.1','name'=>'lti_test','user'=>'root','pass'=>'','port'=>3306],   // XAMPP default root has no password; use your own
  'session'=>['name'=>'LTISESS','idle_minutes'=>30,'absolute_hours'=>12],
  'security'=>['login_max_failures'=>5,'login_window_minutes'=>15,'trust_proxy'=>false],
  'app_url'=>'http://127.0.0.1:8000'];
