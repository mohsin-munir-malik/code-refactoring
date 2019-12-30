Choose ONE of the following tasks.
Please do not invest more than two hours on this.
Upload your results to a Github Gist, for easier sharing and reviewing.

Thank you and good luck!



Code to refactor
=================
1) app/Http/Controllers/BookingController.php
2) app/Repository/BookingRepository.php

Code to write tests
=====================
3) App/Helpers/TeHelper.php method willExpireAt
4) App/Repository/UserRepository.php, method createOrUpdate


------------------------

Refactoring points

1) Mail code separate out to new class
2) Pusher code separated out to new class
3) Job Controller extracted out from BookingController
4) JobRepository extract out from BookingRepository
5) Split lengthy functions to sub smaller functions
6) nested if blocks reduced by using early returns
7) unnecessary isset checks replace with array_get

Note: code can more be refactored using Laravel validation, relations and query scopes.
