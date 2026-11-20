<?php

/*
 * The mailboxes an accountant can pick (App\Http\Controllers\MailAccountController): the SMTP server and the port that provider uses, and
 * where its app password is made, and its IMAP server (where the replies are read from) (an app password is not the mailbox's own password: the provider gives a separate one for programs).
 * provider => [label, host, port, security (tls = STARTTLS on 587, ssl = 465), where to make the app password, IMAP host].
 */
return [
    'gmail' => ['Gmail / Google Workspace', 'smtp.gmail.com', 587, 'tls', 'https://myaccount.google.com/apppasswords', 'imap.gmail.com'],
    'outlook' => ['Outlook / Microsoft 365', 'smtp.office365.com', 587, 'tls', 'https://account.live.com/proofs/AppPassword', 'outlook.office365.com'],
    'yahoo' => ['Yahoo', 'smtp.mail.yahoo.com', 465, 'ssl', 'https://login.yahoo.com/account/security', 'imap.mail.yahoo.com'],
    'zoho' => ['Zoho Mail', 'smtp.zoho.com', 465, 'ssl', 'https://accounts.zoho.com/home#security/app_password', 'imap.zoho.com'],
    'custom' => ['SMTP', '', 587, 'tls', null, ''],
];
