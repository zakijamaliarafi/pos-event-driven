<?php

test('guest is redirected to login from the POS home', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
});
