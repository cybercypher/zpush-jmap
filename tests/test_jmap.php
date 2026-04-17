<?php
/**
 * Z-Push JMAP Backend — Integration Test Suite
 *
 * Exercises every Rust FFI method against a live Stalwart server.
 * Run inside the Docker container via --entrypoint php.
 *
 * Required env vars: STALWART_URL, TEST_USER, TEST_PASSWORD
 * Optional: TEST_DESTRUCTIVE=1  (enables move/trash tests)
 */

// ============================================================
// Configuration from environment
// ============================================================

$STALWART_URL  = getenv('STALWART_URL')  ?: die("[FATAL] STALWART_URL not set\n");
$TEST_USER     = getenv('TEST_USER')     ?: die("[FATAL] TEST_USER not set\n");
$TEST_PASSWORD = getenv('TEST_PASSWORD') ?: die("[FATAL] TEST_PASSWORD not set\n");
$DESTRUCTIVE   = (getenv('TEST_DESTRUCTIVE') === '1');

// ============================================================
// Test framework
// ============================================================

$pass_count = 0;
$fail_count = 0;
$skip_count = 0;

function pass(string $name, string $detail = ''): void {
    global $pass_count;
    $pass_count++;
    $msg = "[PASS] $name";
    if ($detail !== '') $msg .= " — $detail";
    echo "$msg\n";
}

function fail(string $name, string $detail = ''): void {
    global $fail_count;
    $fail_count++;
    $msg = "[FAIL] $name";
    if ($detail !== '') $msg .= " — $detail";
    echo "$msg\n";
}

function skip(string $name, string $reason = ''): void {
    global $skip_count;
    $skip_count++;
    $msg = "[SKIP] $name";
    if ($reason !== '') $msg .= " — $reason";
    echo "$msg\n";
}

function run_test(string $name, callable $fn): void {
    try {
        $fn();
    } catch (\Throwable $e) {
        fail($name, get_class($e) . ': ' . $e->getMessage());
    }
}

// ============================================================
// Shared state across tests
// ============================================================

$client          = null;  // JmapClient
$inbox_id        = null;  // Inbox mailbox ID
$trash_id        = null;  // Trash mailbox ID
$sent_id         = null;  // Sent mailbox ID
$email_ids       = [];    // IDs from queryEmails
$first_email     = null;  // JmapEmail from getEmail
$email_state     = null;  // from getEmailState
$mailbox_state   = null;  // from getMailboxState

echo "=== Z-Push JMAP Integration Tests ===\n";
echo "Server:      $STALWART_URL\n";
echo "User:        $TEST_USER\n";
echo "Destructive: " . ($DESTRUCTIVE ? 'yes' : 'no') . "\n";
echo "====================================\n\n";

// ============================================================
// 1. Connect
// ============================================================
run_test('connect', function () use ($STALWART_URL, $TEST_USER, $TEST_PASSWORD, &$client) {
    if (!function_exists('jmap_connect')) {
        fail('connect', 'jmap_connect() function not found — extension not loaded');
        return;
    }

    $client = jmap_connect($STALWART_URL, $TEST_USER, $TEST_PASSWORD);
    $account_id = $client->getAccountId();

    if (empty($account_id)) {
        fail('connect', 'getAccountId() returned empty');
        return;
    }

    pass('connect', "accountId=$account_id");
});

if ($client === null) {
    echo "\n[FATAL] Cannot proceed without a connection.\n";
    exit(1);
}

// ============================================================
// 2. Mailboxes
// ============================================================
run_test('mailboxes', function () use ($client, &$inbox_id, &$trash_id, &$sent_id) {
    $mailboxes = $client->getMailboxes();

    if (!is_array($mailboxes) || count($mailboxes) === 0) {
        fail('mailboxes', 'getMailboxes() returned empty');
        return;
    }

    echo "  Mailboxes found: " . count($mailboxes) . "\n";
    foreach ($mailboxes as $mb) {
        $role = $mb->role ?? '(none)';
        $parent = $mb->parent_id ?? '(root)';
        echo "    [{$mb->id}] {$mb->name}  role=$role  parent=$parent"
           . "  total={$mb->total_emails}  unread={$mb->unread_emails}\n";

        if ($mb->role === 'inbox')   $inbox_id = $mb->id;
        if ($mb->role === 'trash')   $trash_id = $mb->id;
        if ($mb->role === 'sent')    $sent_id  = $mb->id;
    }

    $missing = [];
    if ($inbox_id === null) $missing[] = 'inbox';
    if ($trash_id === null) $missing[] = 'trash';
    if ($sent_id  === null) $missing[] = 'sent';

    if (!empty($missing)) {
        fail('mailboxes', 'Missing required roles: ' . implode(', ', $missing));
        return;
    }

    pass('mailboxes', "inbox=$inbox_id trash=$trash_id sent=$sent_id");
});

if ($inbox_id === null) {
    echo "\n[FATAL] Cannot proceed without Inbox.\n";
    exit(1);
}

// ============================================================
// 3. Query emails
// ============================================================
run_test('query_emails', function () use ($client, $inbox_id, &$email_ids) {
    $result = $client->queryEmails($inbox_id, null, 20, 0);

    echo "  total={$result->total}  returned=" . count($result->ids)
       . "  can_load_more=" . ($result->can_load_more ? 'true' : 'false') . "\n";

    if (count($result->ids) === 0) {
        fail('query_emails', 'No emails in Inbox — need at least one for further tests');
        return;
    }

    $email_ids = $result->ids;
    $preview = array_slice($email_ids, 0, 3);
    pass('query_emails', 'first IDs: ' . implode(', ', $preview));
});

if (empty($email_ids)) {
    echo "\n[FATAL] Cannot proceed without emails.\n";
    exit(1);
}

// ============================================================
// 4. Email metadata
// ============================================================
run_test('email_metadata', function () use ($client, $email_ids) {
    $batch = array_slice($email_ids, 0, 5);
    $metas = $client->getEmailMetadata($batch);

    if (!is_array($metas) || count($metas) === 0) {
        fail('email_metadata', 'getEmailMetadata() returned empty');
        return;
    }

    foreach ($metas as $m) {
        $kw = implode(',', $m->keywords);
        $mboxes = implode(',', $m->mailbox_ids);
        echo "    [{$m->id}] size={$m->size}  date={$m->received_at}"
           . "  keywords=[$kw]  mailboxes=[$mboxes]\n";
    }

    pass('email_metadata', count($metas) . ' emails fetched');
});

// ============================================================
// 5. Full email
// ============================================================
run_test('full_email', function () use ($client, $email_ids, &$first_email) {
    $first_email = $client->getEmail($email_ids[0]);

    $subj = $first_email->subject ?? '(no subject)';
    $body_snippet = '';
    if ($first_email->text_body !== null) {
        $body_snippet = substr(trim($first_email->text_body), 0, 80);
    } elseif ($first_email->html_body !== null) {
        $body_snippet = substr(strip_tags(trim($first_email->html_body)), 0, 80);
    }

    echo "    subject:     $subj\n";
    echo "    from:        {$first_email->from}\n";
    echo "    to:          {$first_email->to}\n";
    echo "    date:        " . ($first_email->date ?? $first_email->received_at) . "\n";
    echo "    size:        {$first_email->size}\n";
    echo "    blob_id:     {$first_email->blob_id}\n";
    echo "    has_attach:   " . ($first_email->has_attachment ? 'yes' : 'no') . "\n";
    echo "    attach_count: {$first_email->attachment_count}\n";
    echo "    importance:   {$first_email->importance}\n";
    echo "    keywords:     [" . implode(',', $first_email->keywords) . "]\n";
    echo "    body snippet: $body_snippet\n";

    if (empty($first_email->id)) {
        fail('full_email', 'Missing email ID');
        return;
    }

    pass('full_email', "id={$first_email->id} subject=\"$subj\"");
});

if ($first_email === null) {
    echo "\n[WARN] Skipping blob/flag tests — no email loaded.\n";
}

// ============================================================
// 6. Blob download
// ============================================================
run_test('blob_download', function () use ($client, $first_email) {
    if ($first_email === null || empty($first_email->blob_id)) {
        skip('blob_download', 'No email/blob available');
        return;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'jmap_test_');
    try {
        $size = $client->downloadBlobToFile($first_email->blob_id, $tmp);

        if ($size <= 0) {
            fail('blob_download', "Returned size=$size");
            return;
        }

        $actual = filesize($tmp);
        if ($actual !== $size) {
            fail('blob_download', "Size mismatch: returned=$size file=$actual");
            return;
        }

        pass('blob_download', "blob_id={$first_email->blob_id} size=$size bytes");
    } finally {
        @unlink($tmp);
    }
});

// ============================================================
// 7. Set flags (toggle $flagged, then restore)
// ============================================================
run_test('set_flags', function () use ($client, $email_ids) {
    $id = $email_ids[0];

    // Get current keywords
    $metas = $client->getEmailMetadata([$id]);
    $was_flagged = in_array('$flagged', $metas[0]->keywords);
    echo "    before: \$flagged=" . ($was_flagged ? 'true' : 'false') . "\n";

    // Toggle
    $new_val = !$was_flagged;
    $client->setEmailKeywords($id, ['$flagged' => $new_val]);

    // Verify
    $metas2 = $client->getEmailMetadata([$id]);
    $is_flagged = in_array('$flagged', $metas2[0]->keywords);
    echo "    after toggle: \$flagged=" . ($is_flagged ? 'true' : 'false') . "\n";

    if ($is_flagged !== $new_val) {
        fail('set_flags', 'Flag toggle did not take effect');
        // Try to restore anyway
        $client->setEmailKeywords($id, ['$flagged' => $was_flagged]);
        return;
    }

    // Restore original
    $client->setEmailKeywords($id, ['$flagged' => $was_flagged]);

    $metas3 = $client->getEmailMetadata([$id]);
    $restored = in_array('$flagged', $metas3[0]->keywords);
    echo "    restored: \$flagged=" . ($restored ? 'true' : 'false') . "\n";

    if ($restored !== $was_flagged) {
        fail('set_flags', 'Flag restore failed');
        return;
    }

    pass('set_flags', 'Toggle and restore succeeded');
});

// ============================================================
// 8. Email state
// ============================================================
run_test('email_state', function () use ($client, &$email_state) {
    $email_state = $client->getEmailState();

    if (empty($email_state)) {
        fail('email_state', 'getEmailState() returned empty');
        return;
    }

    pass('email_state', "state=$email_state");
});

// ============================================================
// 9. Email changes
// ============================================================
run_test('email_changes', function () use ($client, $email_state) {
    if ($email_state === null) {
        skip('email_changes', 'No email state available');
        return;
    }

    $changes = $client->getEmailChanges($email_state);

    echo "    new_state={$changes->new_state}  has_changes=" . ($changes->has_changes ? 'true' : 'false') . "\n";
    echo "    created=" . count($changes->created)
       . " updated=" . count($changes->updated)
       . " destroyed=" . count($changes->destroyed) . "\n";

    if (empty($changes->new_state)) {
        fail('email_changes', 'Missing new_state');
        return;
    }

    pass('email_changes', "new_state={$changes->new_state}");
});

// ============================================================
// 10. Mailbox state
// ============================================================
run_test('mailbox_state', function () use ($client, &$mailbox_state) {
    $mailbox_state = $client->getMailboxState();

    if (empty($mailbox_state)) {
        fail('mailbox_state', 'getMailboxState() returned empty');
        return;
    }

    pass('mailbox_state', "state=$mailbox_state");
});

// ============================================================
// 11. Mailbox changes
// ============================================================
run_test('mailbox_changes', function () use ($client, $mailbox_state) {
    if ($mailbox_state === null) {
        skip('mailbox_changes', 'No mailbox state available');
        return;
    }

    $changes = $client->getMailboxChanges($mailbox_state);

    echo "    new_state={$changes->new_state}  has_changes=" . ($changes->has_changes ? 'true' : 'false') . "\n";
    echo "    created=" . count($changes->created)
       . " updated=" . count($changes->updated)
       . " destroyed=" . count($changes->destroyed) . "\n";

    if (empty($changes->new_state)) {
        fail('mailbox_changes', 'Missing new_state');
        return;
    }

    pass('mailbox_changes', "new_state={$changes->new_state}");
});

// ============================================================
// 12. Send email (to self)
// ============================================================
run_test('send_email', function () use ($client, $sent_id, $TEST_USER) {
    if ($sent_id === null) {
        skip('send_email', 'No Sent mailbox found');
        return;
    }

    $unique_id = uniqid('zpush-test-', true);
    $date = gmdate('r');

    $mime = "From: $TEST_USER\r\n"
          . "To: $TEST_USER\r\n"
          . "Subject: [TEST] $unique_id\r\n"
          . "Date: $date\r\n"
          . "MIME-Version: 1.0\r\n"
          . "Content-Type: text/plain; charset=utf-8\r\n"
          . "\r\n"
          . "This is an automated test message from z-push JMAP test suite.\r\n"
          . "Test ID: $unique_id\r\n";

    $tmp = tempnam(sys_get_temp_dir(), 'jmap_send_');
    file_put_contents($tmp, $mime);

    try {
        $result = $client->sendEmail($tmp, $sent_id);

        if ($result !== true) {
            fail('send_email', 'sendEmail() returned non-true');
            return;
        }

        pass('send_email', "subject=[TEST] $unique_id");
    } finally {
        @unlink($tmp);
    }
});

// ============================================================
// 13. Move email (destructive — opt-in)
// ============================================================
run_test('move_email', function () use ($client, $email_ids, $inbox_id, $trash_id, $DESTRUCTIVE) {
    if (!$DESTRUCTIVE) {
        skip('move_email', 'TEST_DESTRUCTIVE not set');
        return;
    }
    if ($trash_id === null) {
        skip('move_email', 'No Trash mailbox');
        return;
    }

    $id = $email_ids[0];
    echo "    Moving $id: Inbox -> Trash\n";

    $client->moveEmail($id, $inbox_id, $trash_id);

    // Verify it's in Trash
    $meta = $client->getEmailMetadata([$id]);
    $in_trash = in_array($trash_id, $meta[0]->mailbox_ids);
    echo "    In Trash: " . ($in_trash ? 'yes' : 'no') . "\n";

    if (!$in_trash) {
        fail('move_email', 'Email not found in Trash after move');
        return;
    }

    // Move back
    echo "    Moving $id: Trash -> Inbox\n";
    $client->moveEmail($id, $trash_id, $inbox_id);

    $meta2 = $client->getEmailMetadata([$id]);
    $in_inbox = in_array($inbox_id, $meta2[0]->mailbox_ids);
    echo "    In Inbox: " . ($in_inbox ? 'yes' : 'no') . "\n";

    if (!$in_inbox) {
        fail('move_email', 'Email not found in Inbox after restore');
        return;
    }

    pass('move_email', 'Move to Trash and back succeeded');
});

// ============================================================
// 14. Trash email (destructive — opt-in)
// ============================================================
run_test('trash_email', function () use ($client, $email_ids, $inbox_id, $trash_id, $DESTRUCTIVE) {
    if (!$DESTRUCTIVE) {
        skip('trash_email', 'TEST_DESTRUCTIVE not set');
        return;
    }
    if ($trash_id === null || count($email_ids) < 2) {
        skip('trash_email', 'Need Trash mailbox and at least 2 emails');
        return;
    }

    // Use a different email than move_email test
    $id = $email_ids[1];
    echo "    Trashing $id\n";

    $client->trashEmail($id, $trash_id);

    // Verify it's in Trash
    $meta = $client->getEmailMetadata([$id]);
    $in_trash = in_array($trash_id, $meta[0]->mailbox_ids);
    echo "    In Trash: " . ($in_trash ? 'yes' : 'no') . "\n";

    if (!$in_trash) {
        fail('trash_email', 'Email not in Trash after trashEmail()');
        return;
    }

    // Restore: move from Trash back to Inbox
    echo "    Restoring $id: Trash -> Inbox\n";
    $client->moveEmail($id, $trash_id, $inbox_id);

    $meta2 = $client->getEmailMetadata([$id]);
    $in_inbox = in_array($inbox_id, $meta2[0]->mailbox_ids);
    echo "    In Inbox: " . ($in_inbox ? 'yes' : 'no') . "\n";

    if (!$in_inbox) {
        fail('trash_email', 'Email not restored to Inbox');
        return;
    }

    pass('trash_email', 'Trash and restore succeeded');
});

// ============================================================
// 15. Address books
// ============================================================
$address_books = [];
$default_ab_id = null;

run_test('address_books', function () use ($client, &$address_books, &$default_ab_id) {
    $address_books = $client->getAddressBooks();

    if (!is_array($address_books) || count($address_books) === 0) {
        fail('address_books', 'getAddressBooks() returned empty');
        return;
    }

    echo "  Address books found: " . count($address_books) . "\n";
    foreach ($address_books as $ab) {
        $def = $ab->is_default ? ' (default)' : '';
        echo "    [{$ab->id}] {$ab->name}$def\n";
        if ($ab->is_default) $default_ab_id = $ab->id;
    }

    if ($default_ab_id === null) {
        // Use first one as fallback
        $default_ab_id = $address_books[0]->id;
    }

    pass('address_books', count($address_books) . " book(s), default=$default_ab_id");
});

// ============================================================
// 16. Query contacts
// ============================================================
$contact_ids = [];

run_test('query_contacts', function () use ($client, $default_ab_id, &$contact_ids) {
    if ($default_ab_id === null) {
        skip('query_contacts', 'No address book available');
        return;
    }

    $result = $client->queryContacts($default_ab_id, 20, 0);

    echo "  total={$result->total}  returned=" . count($result->ids) . "\n";

    $contact_ids = $result->ids;

    if (count($contact_ids) === 0) {
        pass('query_contacts', 'No contacts found (empty book — OK)');
        return;
    }

    $preview = array_slice($contact_ids, 0, 3);
    pass('query_contacts', 'first IDs: ' . implode(', ', $preview));
});

// ============================================================
// 17. Contact detail
// ============================================================
run_test('contact_detail', function () use ($client, $contact_ids) {
    if (empty($contact_ids)) {
        skip('contact_detail', 'No contacts available');
        return;
    }

    $contact = $client->getContact($contact_ids[0]);

    echo "    id:         {$contact->id}\n";
    echo "    first_name: {$contact->first_name}\n";
    echo "    last_name:  {$contact->last_name}\n";
    echo "    email1:     {$contact->email1}\n";
    echo "    company:    {$contact->company}\n";
    echo "    has_photo:  " . ($contact->has_photo ? 'yes' : 'no') . "\n";

    if (empty($contact->id)) {
        fail('contact_detail', 'Missing contact ID');
        return;
    }

    pass('contact_detail', "id={$contact->id} name={$contact->first_name} {$contact->last_name}");
});

// ============================================================
// 18. Contact metadata
// ============================================================
run_test('contact_metadata', function () use ($client, $contact_ids) {
    if (empty($contact_ids)) {
        skip('contact_metadata', 'No contacts available');
        return;
    }

    $batch = array_slice($contact_ids, 0, 5);
    $metas = $client->getContactMetadata($batch);

    if (!is_array($metas) || count($metas) === 0) {
        fail('contact_metadata', 'getContactMetadata() returned empty');
        return;
    }

    foreach ($metas as $m) {
        $books = implode(',', $m->address_book_ids);
        echo "    [{$m->id}] books=[$books]\n";
    }

    pass('contact_metadata', count($metas) . ' contacts fetched');
});

// ============================================================
// 19. Contact state/changes
// ============================================================
$contact_state = null;

run_test('contact_state', function () use ($client, &$contact_state) {
    $contact_state = $client->getContactState();

    if (empty($contact_state)) {
        fail('contact_state', 'getContactState() returned empty');
        return;
    }

    pass('contact_state', "state=$contact_state");
});

run_test('contact_changes', function () use ($client, $contact_state) {
    if ($contact_state === null) {
        skip('contact_changes', 'No contact state available');
        return;
    }

    $changes = $client->getContactChanges($contact_state);

    echo "    new_state={$changes->new_state}  has_changes=" . ($changes->has_changes ? 'true' : 'false') . "\n";
    echo "    created=" . count($changes->created)
       . " updated=" . count($changes->updated)
       . " destroyed=" . count($changes->destroyed) . "\n";

    if (empty($changes->new_state)) {
        fail('contact_changes', 'Missing new_state');
        return;
    }

    pass('contact_changes', "new_state={$changes->new_state}");
});

// ============================================================
// 20. Calendars
// ============================================================
$calendars = [];
$default_cal_id = null;

run_test('calendars', function () use ($client, &$calendars, &$default_cal_id) {
    $calendars = $client->getCalendars();

    if (!is_array($calendars) || count($calendars) === 0) {
        fail('calendars', 'getCalendars() returned empty');
        return;
    }

    echo "  Calendars found: " . count($calendars) . "\n";
    foreach ($calendars as $cal) {
        $def = $cal->is_default ? ' (default)' : '';
        echo "    [{$cal->id}] {$cal->name}$def\n";
        if ($cal->is_default) $default_cal_id = $cal->id;
    }

    if ($default_cal_id === null) {
        $default_cal_id = $calendars[0]->id;
    }

    pass('calendars', count($calendars) . " calendar(s), default=$default_cal_id");
});

// ============================================================
// 21. Query calendar events
// ============================================================
$event_ids = [];

run_test('query_events', function () use ($client, $default_cal_id, &$event_ids) {
    if ($default_cal_id === null) {
        skip('query_events', 'No calendar available');
        return;
    }

    // Query events from last 30 days
    $after = gmdate('Y-m-d\TH:i:s\Z', time() - 30 * 86400);
    $result = $client->queryCalendarEvents($default_cal_id, $after, null, 20, 0);

    echo "  total={$result->total}  returned=" . count($result->ids) . "\n";

    $event_ids = $result->ids;

    if (count($event_ids) === 0) {
        pass('query_events', 'No events found in range (OK)');
        return;
    }

    $preview = array_slice($event_ids, 0, 3);
    pass('query_events', 'first IDs: ' . implode(', ', $preview));
});

// ============================================================
// 22. Calendar event detail
// ============================================================
run_test('event_detail', function () use ($client, $event_ids) {
    if (empty($event_ids)) {
        skip('event_detail', 'No events available');
        return;
    }

    $event = $client->getCalendarEvent($event_ids[0]);

    echo "    id:          {$event->id}\n";
    echo "    uid:         {$event->uid}\n";
    echo "    title:       {$event->title}\n";
    echo "    utc_start:   {$event->utc_start}\n";
    echo "    utc_end:     {$event->utc_end}\n";
    echo "    time_zone:   {$event->time_zone}\n";
    echo "    all_day:     " . ($event->all_day ? 'yes' : 'no') . "\n";
    echo "    location:    {$event->location}\n";
    echo "    organizer:   {$event->organizer_name} <{$event->organizer_email}>\n";
    echo "    busy_status: {$event->busy_status}\n";

    if (empty($event->id)) {
        fail('event_detail', 'Missing event ID');
        return;
    }

    pass('event_detail', "id={$event->id} title=\"{$event->title}\"");
});

// ============================================================
// 23. Calendar event metadata
// ============================================================
run_test('event_metadata', function () use ($client, $event_ids) {
    if (empty($event_ids)) {
        skip('event_metadata', 'No events available');
        return;
    }

    $batch = array_slice($event_ids, 0, 5);
    $metas = $client->getCalendarEventMetadata($batch);

    if (!is_array($metas) || count($metas) === 0) {
        fail('event_metadata', 'getCalendarEventMetadata() returned empty');
        return;
    }

    foreach ($metas as $m) {
        $cals = implode(',', $m->calendar_ids);
        echo "    [{$m->id}] uid={$m->uid} start={$m->utc_start} cals=[$cals]\n";
    }

    pass('event_metadata', count($metas) . ' events fetched');
});

// ============================================================
// 24. Calendar event state/changes
// ============================================================
$cal_event_state = null;

run_test('calendar_event_state', function () use ($client, &$cal_event_state) {
    $cal_event_state = $client->getCalendarEventState();

    if (empty($cal_event_state)) {
        fail('calendar_event_state', 'getCalendarEventState() returned empty');
        return;
    }

    pass('calendar_event_state', "state=$cal_event_state");
});

run_test('calendar_event_changes', function () use ($client, $cal_event_state) {
    if ($cal_event_state === null) {
        skip('calendar_event_changes', 'No calendar event state available');
        return;
    }

    $changes = $client->getCalendarEventChanges($cal_event_state);

    echo "    new_state={$changes->new_state}  has_changes=" . ($changes->has_changes ? 'true' : 'false') . "\n";
    echo "    created=" . count($changes->created)
       . " updated=" . count($changes->updated)
       . " destroyed=" . count($changes->destroyed) . "\n";

    if (empty($changes->new_state)) {
        fail('calendar_event_changes', 'Missing new_state');
        return;
    }

    pass('calendar_event_changes', "new_state={$changes->new_state}");
});

// ============================================================
// Summary
// ============================================================

echo "\n====================================\n";
echo "Results: $pass_count passed, $fail_count failed, $skip_count skipped\n";
echo "====================================\n";

exit($fail_count > 0 ? 1 : 0);
