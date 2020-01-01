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

-------------------------

Bad Code:

- There are code duplication.
- Variable names doesn’t provide code context.
- Too many Nested blocks, hard to debug and understand.
- Curl, email, pusher, All the code is merged in one file. SRP Pattern was violated.
- By only looking at the code, it was giving impressions that Eloquent Relationships were not properly defined and not properly been used.
- Validation, response formation, this code is in repository which is totally wrong.
- Validation is done through PHP code, Laravel validator was not used.
- I didn’t refactor Repo class because it is one Giant of a class with 2000+ lines of code, everything is stuffed in here.
