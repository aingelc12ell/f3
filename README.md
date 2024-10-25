# fatfree-core
Fat-Free Framework core library

### *Important Note*:

Since there have been no progress nor updates since v3.8.2, and pull requests have been open for sometime, this fork will be revised to keep this library alive. 
See the [Changelog](https://github.com/aingelc12ell/f3/blob/master/CHANGELOG.md) for changes being made.
New release branch and package will be available soon.

### Usage:

First make sure to add a proper url rewrite configuration to your server, see https://fatfreeframework.com/3.6/routing-engine#DynamicWebSites

**without composer:**

```php
## deprecated: $f3 = require('lib/base.php');

require('lib/base.php');
$f3 = Base::instance();
```

**with composer:**

```
composer require bcosca/fatfree-core
```

```php
require("vendor/autoload.php");
$f3 = \Base::instance();
```

---
For the main repository (demo package), see https://github.com/bcosca/fatfree  
For the test bench and unit tests, see https://github.com/f3-factory/fatfree-dev  
For the user guide, see https://fatfreeframework.com/user-guide  
For the documentation, see https://fatfreeframework.com/api-reference
