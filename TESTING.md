
* Class extensions
  * `XF\Admin\Controller\Tools`
    *
    *
  * `XF\Service\User\Registration`
    * 


* Code event listeners
  * `app_admin_setup`
    * register `geoblock.test` factory
  * `app_setup`
    * register `geoblock` subcontainer
  * `spam_user_providers`
    * register Geoblock spam provider


* Fragile points:
	

* Testing:
  * run unit tests
  * ...