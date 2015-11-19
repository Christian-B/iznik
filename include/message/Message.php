<?php

require_once(IZNIK_BASE . '/include/utils.php');
require_once(IZNIK_BASE . '/include/misc/Log.php');
require_once(IZNIK_BASE . '/include/misc/plugin.php');
require_once(IZNIK_BASE . '/include/group/Group.php');
require_once(IZNIK_BASE . '/include/user/User.php');
require_once(IZNIK_BASE . '/include/message/Attachment.php');
require_once(IZNIK_BASE . '/include/message/Collection.php');
require_once(IZNIK_BASE . '/include/misc/Image.php');

class Message
{
    const TYPE_OFFER = 'Offer';
    const TYPE_TAKEN = 'Taken';
    const TYPE_WANTED = 'Wanted';
    const TYPE_RECEIVED = 'Received';
    const TYPE_ADMIN = 'Admin';
    const TYPE_OTHER = 'Other';

    /**
     * @return null
     */
    public function getGroupid()
    {
        return $this->groupid;
    }

    /**
     * @return mixed
     */
    public function getYahooapprove()
    {
        return $this->yahooapprove;
    }

    public function setYahooPendingId($groupid, $id) {
        $sql = "UPDATE messages_groups SET yahoopendingid = ? WHERE msgid = {$this->id} AND groupid = ?;";
        $rc = $this->dbhm->preExec($sql, [ $id, $groupid ]);
        $this->yahoopendingid = $id;
    }

    public function setYahooApprovedId($groupid, $id) {
        $sql = "UPDATE messages_groups SET yahooapprovedid = ? WHERE msgid = {$this->id} AND groupid = ?;";
        $rc = $this->dbhm->preExec($sql, [ $id, $groupid ]);
        $this->yahooapprovedid = $id;
    }

    /**
     * @return mixed
     */
    public function getYahooreject()
    {
        return $this->yahooreject;
    }

    /** @var  $dbhr LoggedPDO */
    private $dbhr;
    /** @var  $dbhm LoggedPDO */
    private $dbhm;

    private $id, $source, $sourceheader, $message, $textbody, $htmlbody, $subject, $fromname, $fromaddr,
        $envelopefrom, $envelopeto, $messageid, $tnpostid, $fromip, $date,
        $fromhost, $type, $attachments, $yahoopendingid, $yahooapprovedid, $yahooreject, $yahooapprove, $attach_dir, $attach_files,
        $parser, $arrival, $spamreason, $fromuser;

    # The groupid is only used for parsing and saving incoming messages; after that a message can be on multiple
    # groups as is handled via the messages_groups table.
    private $groupid = NULL;

    private $inlineimgs = [];

    /**
     * @return mixed
     */
    public function getSpamreason()
    {
        return $this->spamreason;
    }

    # Each message has some public attributes, which are visible to API users.
    #
    # Which attributes can be seen depends on the currently logged in user's role on the group.
    #
    # Other attributes are only visible within the server code.
    public $nonMemberAtts = [
        'id', 'subject', 'type', 'arrival', 'date'
    ];

    public $memberAtts = [
        'textbody', 'htmlbody', 'fromname', 'fromuser'
    ];

    public $moderatorAtts = [
        'source', 'sourceheader', 'fromaddr', 'envelopeto', 'envelopefrom', 'messageid', 'tnpostid',
        'fromip', 'message', 'spamreason'
    ];

    public $ownerAtts = [
        # Add in a dup for UT coverage of loop below.
        'source'
    ];

    function __construct(LoggedPDO $dbhr, LoggedPDO $dbhm, $id = NULL)
    {
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $this->log = new Log($this->dbhr, $this->dbhm);

        if ($id) {
            $msgs = $dbhr->preQuery("SELECT * FROM messages WHERE id = ?;", [$id]);
            foreach ($msgs as $msg) {
                $this->id = $id;

                foreach (array_merge($this->nonMemberAtts, $this->memberAtts, $this->moderatorAtts, $this->ownerAtts) as $attr) {
                    if (pres($attr, $msg)) {
                        $this->$attr = $msg[$attr];
                    }
                }
            }

            # TODO We don't need to parse each time.
            $this->parser = new PhpMimeMailParser\Parser();
            $this->parser->setText($this->message);
        }
    }

    # Default mailer is to use the standard PHP one, but this can be overridden in UT.
    private function mailer() {
        call_user_func_array('mail', func_get_args());
    }

    public function getRoleForMessage() {
        # Our role for a message is the highest role we have on any group that this message is on.  That means that
        # we have limited access to information on other groups of which we are not a moderator, but that is legitimate
        # if the message is on our group.
        $me = whoAmI($this->dbhr, $this->dbhm);
        $role = User::ROLE_NONMEMBER;

        if ($me) {
            $sql = "SELECT role FROM memberships
              INNER JOIN messages_groups ON messages_groups.msgid = ?
                  AND messages_groups.groupid = memberships.groupid
                  AND userid = ?;";
            $groups = $this->dbhr->preQuery($sql, [
                $this->id,
                $me->getId()
            ]);

            foreach ($groups as $group) {
                switch ($group['role']) {
                    case User::ROLE_OWNER:
                        # Owner is highest.
                        $role = $group['role'];
                        break;
                    case User::ROLE_MODERATOR:
                        # Upgrade from member or non-member to mod.
                        $role = ($role == User::ROLE_MEMBER || $role == User::ROLE_NONMEMBER) ? User::ROLE_MODERATOR : $role;
                        break;
                    case User::ROLE_MEMBER:
                        # Just a member
                        $role = User::ROLE_MEMBER;
                        break;
                }
            }
        }

        return($role);
    }

    public function getPublic() {
        $ret = [];
        $role = $this->getRoleForMessage();

        foreach ($this->nonMemberAtts as $att) {
            $ret[$att] = $this->$att;
        }

        if ($role == User::ROLE_MEMBER || $role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER) {
            foreach ($this->memberAtts as $att) {
                $ret[$att] = $this->$att;
            }
        }

        if ($role == User::ROLE_MODERATOR || $role == User::ROLE_OWNER) {
            foreach ($this->moderatorAtts as $att) {
                $ret[$att] = $this->$att;
            }
        }

        if ($role == User::ROLE_OWNER) {
            foreach ($this->ownerAtts as $att) {
                $ret[$att] = $this->$att;
            }
        }

        # Remove any group subject tag.
        $ret['subject'] = preg_replace('/\[.*\]\s*/', '', $ret['subject']);

        # Add any groups that this message is on.
        $ret['groups'] = [];
        $sql = "SELECT * FROM messages_groups WHERE msgid = ?;";
        $ret['groups'] = $this->dbhr->preQuery($sql, [ $this->id ] );

        foreach ($ret['groups'] as &$group) {
            $group['arrival'] = ISODate($group['arrival']);
        }

        $ret['arrival'] = ISODate($ret['arrival']);
        $ret['date'] = ISODate($ret['date']);

        if (pres('fromuser', $ret)) {
            $u = new User($this->dbhr, $this->dbhm, $ret['fromuser']);

            # Get the user details, relative to the groups this message appears on.
            $ret['fromuser'] = $u->getPublic($this->getGroups());
            filterResult($ret['fromuser']);
        }

        # Add any attachments - visible to non-members.
        $ret['attachments'] = [];
        $atts = $this->getAttachments();

        foreach ($atts as $att) {
            /** @var $att Attachment */
            $ret['attachments'][] = $att->getPublic();
        }

        return($ret);
    }

    /**
     * @return mixed
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @return mixed
     */
    public function getFromIP()
    {
        return $this->fromip;
    }

    /**
     * @return mixed
     */
    public function getSource()
    {
        return $this->source;
    }

    /**
     * @return mixed
     */
    public function getSourceheader()
    {
        return $this->sourceheader;
    }

    /**
     * @param mixed $fromip
     */
    public function setFromIP($fromip)
    {
        $this->fromip = $fromip;
        $name = NULL;

        if ($fromip) {
            # If the call returns a hostname which is the same as the IP, then it's
            # not resolvable.
            $name = gethostbyaddr($fromip);
            $name = ($name == $fromip) ? NULL : $name;
            $this->fromhost = $name;
        }

        $this->dbhm->preExec("UPDATE messages SET fromip = ? WHERE id = ?;",
            [$fromip, $this->id]);
        $this->dbhm->preExec("UPDATE messages_history SET fromip = ?, fromhost = ? WHERE msgid = ?;",
            [$fromip, $name, $this->id]);
    }

    /**
     * @return mixed
     */
    public function getFromhost()
    {
        return $this->fromhost;
    }

    const EMAIL = 'Email';
    const YAHOO_APPROVED = 'Yahoo Approved';
    const YAHOO_PENDING = 'Yahoo Pending';

    /**
     * @return null
     */
    public function getID()
    {
        return $this->id;
    }

    /**
     * @return mixed
     */
    public function getMessageID()
    {
        return $this->messageid;
    }

    /**
     * @return mixed
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @return mixed
     */
    public function getTnpostid()
    {
        return $this->tnpostid;
    }

    /**
     * @return mixed
     */
    public function getEnvelopefrom()
    {
        return $this->envelopefrom;
    }

    /**
     * @return mixed
     */
    public function getEnvelopeto()
    {
        return $this->envelopeto;
    }

    /**
     * @return mixed
     */
    public function getFromname()
    {
        return $this->fromname;
    }

    /**
     * @return mixed
     */
    public function getFromaddr()
    {
        return $this->fromaddr;
    }

    /**
     * @return mixed
     */
    public function getTextbody()
    {
        return $this->textbody;
    }

    /**
     * @return mixed
     */
    public function getHtmlbody()
    {
        return $this->htmlbody;
    }

    /**
     * @return mixed
     */
    public function getSubject()
    {
        return $this->subject;
    }

    /**
     * @return array
     */
    public function getInlineimgs()
    {
        return $this->inlineimgs;
    }

    /**
     * @return PhpMimeMailParser\Attachment[]
     */
    public function getParsedAttachments()
    {
        return $this->attachments;
    }

    # Get attachments which have been saved
    public function getAttachments() {
        $atts = Attachment::getById($this->dbhr, $this->dbhm, $this->getID());
        return($atts);
    }

    public static function determineType($subj) {
        $type = Message::TYPE_OTHER;

        # We try various mis-spellings, and Welsh.  This is not to suggest that Welsh is a spelling error.
        $keywords = [
            Message::TYPE_OFFER => [
                'ofer', 'offr', 'offrer', 'ffered', 'offfered', 'offrered', 'offered', 'offeer', 'cynnig', 'offred',
                'offer', 'offering', 'reoffer', 're offer', 're-offer', 'reoffered', 're offered', 're-offered',
                'offfer', 'offeed', 'available'],
            Message::TYPE_TAKEN => ['collected', 'take', 'stc', 'gone', 'withdrawn', 'ta ke n', 'promised',
                'cymeryd', 'cymerwyd', 'takln', 'taken'],
            Message::TYPE_WANTED => ['wnted', 'requested', 'rquested', 'request', 'would like', 'want',
                'anted', 'wated', 'need', 'needed', 'wamted', 'require', 'required', 'watnted', 'wented',
                'sought', 'seeking', 'eisiau', 'wedi eisiau', 'eisiau', 'wnated', 'wanted', 'looking', 'waned'],
            Message::TYPE_RECEIVED => ['recieved', 'reiceved', 'receved', 'rcd', 'rec\'d', 'recevied',
                'receive', 'derbynewid', 'derbyniwyd', 'received', 'recivered'],
            Message::TYPE_ADMIN => ['admin', 'sn']
        ];

        foreach ($keywords as $keyword => $vals) {
            foreach ($vals as $val) {
                if (preg_match('/\b' . preg_quote($val) . '\b/i', $subj)) {
                    $type = $keyword;
                }
            }
        }

        return($type);
    }

    public function getPrunedSubject() {
        $subj = $this->getSubject();

        if (preg_match('/(.*)\(.*\)/', $subj, $matches)) {
            # Strip possible location - useful for reuse groups
            $subj = $matches[1];
        }
        if (preg_match('/\[.*\](.*)/', $subj, $matches)) {
            # Strip possible group name
            $subj = $matches[1];
        }

        $subj = trim($subj);
        return($subj);
    }

    # Parse a raw SMTP message.
    public function parse($source, $envelopefrom, $envelopeto, $msg)
    {
        $this->message = $msg;

        $Parser = new PhpMimeMailParser\Parser();
        $this->parser = $Parser;
        $Parser->setText($msg);

        # We save the attachments to a temp directory.  This is tidied up on destruction or save.
        $this->attach_dir = tmpdir();
        $this->attach_files = $Parser->saveAttachments($this->attach_dir . DIRECTORY_SEPARATOR);
        $this->attachments = $Parser->getAttachments();
        $this->yahooapprove = NULL;
        $this->yahooreject = NULL;

        if ($source == Message::YAHOO_PENDING) {
            # This is an APPROVE mail; we need to extract the included copy of the original message.
            $this->yahooapprove = $Parser->getHeader('reply-to');
            if (preg_match('/^(.*-reject-.*yahoogroups.*?)($| |=)/im', $msg, $matches)) {
                $this->yahooreject = trim($matches[1]);
            }

            $atts = $this->getParsedAttachments();
            if (count($atts) >= 1 && $atts[0]->contentType == 'message/rfc822') {
                $attachedmsg = $atts[0]->getContent();
                $Parser->setText($attachedmsg);
                $this->attach_files = $Parser->saveAttachments($this->attach_dir);
                $this->attachments = $Parser->getAttachments();
            }
        }

        if (count($this->attachments) == 0) {
            # No attachments - tidy up temp dir.
            rrmdir($this->attach_dir);
        }

        $this->source = $source;
        $this->envelopefrom = $envelopefrom;
        $this->envelopeto = $envelopeto;
        $this->yahoopendingid = NULL;
        $this->yahooapprovedid = NULL;

        # Get Yahoo pending message id
        if (preg_match('/pending\?view=1&msg=(\d*)/im', $msg, $matches)) {
            $this->yahoopendingid = $matches[1];
        }

        # Get Yahoo approved message id
        $newmanid = $Parser->getHeader('x-yahoo-newman-id');
        if ($newmanid && preg_match('/.*\-m(\d*)/', $newmanid, $matches)) {
            $this->yahooapprovedid = $matches[1];
        }

        # Yahoo posts messages from the group address, but with a header showing the
        # original from address.
        $originalfrom = $Parser->getHeader('x-original-from');

        if ($originalfrom) {
            $from = mailparse_rfc822_parse_addresses($originalfrom);
        } else {
            $from = mailparse_rfc822_parse_addresses($Parser->getHeader('from'));
        }

        $this->fromname = $from[0]['display'];
        $this->fromaddr = $from[0]['address'];
        $this->date = gmdate("Y-m-d H:i:s", strtotime($Parser->getHeader('date')));

        $this->sourceheader = $Parser->getHeader('x-freegle-source');
        $this->sourceheader = ($this->sourceheader == 'Unknown' ? NULL : $this->sourceheader);

        if (!$this->sourceheader) {
            $this->sourceheader = $Parser->getHeader('x-trash-nothing-source');
            if ($this->sourceheader) {
                $this->sourceheader = "TN-" . $this->sourceheader;
            }
        }

        if (!$this->sourceheader && $Parser->getHeader('x-mailer') == 'Yahoo Groups Message Poster') {
            $this->sourceheader = 'Yahoo-Web';
        }

        if (!$this->sourceheader && (strpos($Parser->getHeader('x-mailer'), 'Freegle Message Maker') !== FALSE)) {
            $this->sourceheader = 'MessageMaker';
        }

        if (!$this->sourceheader) {
            if (stripos($this->fromaddr, 'ilovefreegle.org') !== FALSE) {
                $this->sourceheader = 'FDv2';
            } else {
                $this->sourceheader = 'Yahoo-Email';
            }
        }

        $this->subject = $Parser->getHeader('subject');
        $this->messageid = $Parser->getHeader('message-id');
        $this->messageid = str_replace('<', '', $this->messageid);
        $this->messageid = str_replace('>', '', $this->messageid);
        $this->tnpostid = $Parser->getHeader('x-trash-nothing-post-id');

        $this->textbody = $Parser->getMessageBody('text');
        $this->htmlbody = $Parser->getMessageBody('html');

        # The HTML body might contain images as img tags, rather than actual attachments.  Extract these too.
        $doc = new DOMDocument();
        @$doc->loadHTML($this->htmlbody);
        $imgs = $doc->getElementsByTagName('img');

        foreach ($imgs as $img) {
            $src = $img->getAttribute('src');

            # We only want to get images from http or https to avoid the security risk of fetching a local file.
            if (stripos($src, 'http://') === 0 || stripos($src, 'https://') === 0) {
                $ctx = stream_context_create(array('http'=>
                    array(
                        'timeout' => 30,  # Only wait for 30 seconds to fetch an image.
                    )
                ));
                $data = file_get_contents($src, false, $ctx);

                # Try to convert to an image.  If it's not an image, this will fail.
                $img = new Image($data);
                $newdata = $img->getData(100);

                # Ignore small images - Yahoo adds small ones as (presumably) a tracking mechanism, and also their
                # logo.
                if ($newdata && $img->width() > 50 && $img->height() > 50) {
                    $this->inlineimgs[] = $newdata;
                }
            }
        }

        # See if we can find a group this is intended for.
        $groupname = NULL;
        $to = $this->getTo();
        foreach ($to as $t) {
            if (preg_match('/(.*)@yahoogroups\.co.*/', $t['address'], $matches)) {
                $groupname = $matches[1];
            }
        }

        if ($groupname) {
            # Check if it's a group we host.
            $g = new Group($this->dbhr, $this->dbhm);
            $this->groupid = $g->findByShortName($groupname);

            if ($this->groupid) {
                # If this is a reuse group, we need to determine the type.
                $g = new Group($this->dbhr, $this->dbhm, $this->groupid);
                if ($g->getPrivate('type') == Group::GROUP_FREEGLE ||
                    $g->getPrivate('type') == Group::GROUP_REUSE
                ) {
                    $this->type = $this->determineType($this->subject);
                } else {
                    $this->type = Message::TYPE_OTHER;
                }

                if ($source == Message::YAHOO_PENDING || $source == Message::YAHOO_APPROVED) {
                    # Make sure we have a user and a membership for the originator of this message; they were a member
                    # at the time they sent this.  If they have since left we'll pick that up later via a sync.
                    $u = new User($this->dbhr, $this->dbhm);
                    $userid = $u->findByEmail($this->fromaddr);

                    if (!$userid) {
                        # We don't know them.  Add.
                        #
                        # We don't have a first and last name, so use what we have. If the friendly name is set to an
                        # email address, take the first part.
                        $name = $this->fromname;
                        if (preg_match('/(.*)@/', $name, $matches)) {
                            $name = $matches[1];
                        }

                        if ($userid = $u->create(NULL, NULL, $name)) {
                            # If any of these fail, then we'll pick it up later when we do a sync with the source group,
                            # so no need for a transaction.
                            $u = new User($this->dbhr, $this->dbhm, $userid);
                            $u->addEmail($this->fromaddr, TRUE);
                            $l = new Log($this->dbhr, $this->dbhm);
                            $l->log([
                                'type' => Log::TYPE_USER,
                                'subtype' => Log::SUBTYPE_CREATED,
                                'msgid' => $this->id,
                                'user' => $userid,
                                'text' => 'First seen on incoming message',
                                'groupid' => $this->groupid
                            ]);

                            $u->addMembership($this->groupid);
                        }
                    }

                    # Now we have a user.  If there is a Yahoo uid in here - which there isn't always - add it to the
                    # user entry.
                    $gp = $Parser->getHeader('x-yahoo-group-post');
                    if ($gp && preg_match('/u=(.*);/', $gp, $matches)) {
                        // This is Yahoo's unique identifier for this user.
                        $u = new User($this->dbhr, $this->dbhm, $userid);

                        if ($u->getPrivate('yahooUserId') != $matches[1]) {
                            $u->setPrivate('yahooUserId', $matches[1]);
                        }
                    }

                    $this->fromuser = $userid;
                }
            }
        }
    }
    # Save a parsed message to the DB
    public function save() {
        # A message we are saving as approved may previously have been in system, for example as pending.  When it
        # comes back to us, it might not be the same, so we should remove any old one first.
        #
        # This can happen if a message is handled on another system, e.g. moderated directly on Yahoo.
        #
        # We don't need a transaction for this - transactions aren't great for scalability and worst case we
        # leave a spurious message around which a mod will handle.
        $this->removeByMessageID($this->groupid);

        # Save into the messages table.
        $sql = "INSERT INTO messages (date, source, sourceheader, message, fromuser, envelopefrom, envelopeto, fromname, fromaddr, subject, messageid, tnpostid, textbody, htmlbody, type) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?);";
        $rc = $this->dbhm->preExec($sql, [
            $this->date,
            $this->source,
            $this->sourceheader,
            $this->message,
            $this->fromuser,
            $this->envelopefrom,
            $this->envelopeto,
            $this->fromname,
            $this->fromaddr,
            $this->subject,
            $this->messageid,
            $this->tnpostid,
            $this->textbody,
            $this->htmlbody,
            $this->type
        ]);

        $id = NULL;

        if ($rc) {
            $id = $this->dbhm->lastInsertId();

            $this->id = $id;

            $l = new Log($this->dbhr, $this->dbhm);
            $l->log([
                'type' => Log::TYPE_MESSAGE,
                'subtype' => Log::SUBTYPE_RECEIVED,
                'msgid' => $id,
                'text' => $this->messageid,
                'groupid' => $this->groupid
            ]);
        }

        if ($this->groupid) {
            # Save the group we're on.  If we crash or fail at this point we leave the message stranded, which is ok
            # given the perf cost of a transaction.
            $this->dbhm->preExec("INSERT INTO messages_groups (msgid, groupid, yahoopendingid, yahooapprovedid, yahooreject, yahooapprove, collection) VALUES (?,?,?,?,?,?,?);", [
                $this->id,
                $this->groupid,
                $this->yahoopendingid,
                $this->yahooapprovedid,
                $this->yahooreject,
                $this->yahooapprove,
                Collection::INCOMING
            ]);
        }

        # Save the attachments.
        #
        # If we crash or fail at this point, we would have mislaid an attachment for a message.  That's not great, but the
        # perf cost of a transaction for incoming messages is significant, and we can live with it.
        foreach ($this->attachments as $att) {
            /** @var \PhpMimeMailParser\Attachment $att */
            $ct = $att->getContentType();
            $fn = $this->attach_dir . DIRECTORY_SEPARATOR . $att->getFilename();

            # Can't use LOAD_FILE as server may be remote.
            $data = file_get_contents($fn);
            $sql = "INSERT INTO messages_attachments (msgid, contenttype, data) VALUES (?,?,?);";
            $this->dbhm->preExec($sql, [
                $this->id,
                $ct,
                $data
            ]);
        }

        foreach ($this->inlineimgs as $att) {
            $sql = "INSERT INTO messages_attachments (msgid, contenttype, data) VALUES (?,?,?);";
            $this->dbhm->preExec($sql, [
                $this->id,
                'image/jpeg',
                $att
            ]);
        }

        # Also save into the history table, for spam checking.
        $sql = "INSERT INTO messages_history (groupid, source, message, fromuser, envelopefrom, envelopeto, fromname, fromaddr, subject, prunedsubject, messageid, textbody, htmlbody, msgid) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?);";
        $this->dbhm->preExec($sql, [
            $this->groupid,
            $this->source,
            $this->message,
            $this->fromuser,
            $this->envelopefrom,
            $this->envelopeto,
            $this->fromname,
            $this->fromaddr,
            $this->subject,
            $this->getPrunedSubject(),
            $this->messageid,
            $this->textbody,
            $this->htmlbody,
            $this->id
        ]);

        return($id);
    }

    function recordFailure($reason) {
        $this->dbhm->preExec("UPDATE messages SET retrycount = LAST_INSERT_ID(retrycount),
          retrylastfailure = NOW() WHERE id = ?;", [$this->id]);
        $count = $this->dbhm->lastInsertId();

        $l = new Log($this->dbhr, $this->dbhm);
        $l->log([
            'type' => Log::TYPE_MESSAGE,
            'subtype' => Log::SUBTYPE_FAILURE,
            'msgid' => $this->id,
            'text' => $reason
        ]);

        return($count);
    }

    public function getHeader($hdr) {
        return($this->parser->getHeader($hdr));
    }

    public function getTo() {
        return(mailparse_rfc822_parse_addresses($this->parser->getHeader('to')));
    }

    public function getGroups() {
        $ret = [];
        $sql = "SELECT groupid FROM messages_groups WHERE msgid = ?;";
        $groups = $this->dbhr->preQuery($sql, [ $this->id ]);
        foreach ($groups as $group) {
            $ret[] = $group['groupid'];
        }

        return($ret);
    }

    public function isPending($groupid) {
        $ret = false;
        $sql = "SELECT msgid FROM messages_groups WHERE msgid = ? AND groupid = ? AND collection = ? AND deleted = 0;";
        $groups = $this->dbhr->preQuery($sql, [
            $this->id,
            $groupid,
            Collection::PENDING
        ]);

        return(count($groups) > 0);
    }

    public function reject($groupid, $subject, $body) {
        # No need for a transaction - if things go wrong, the message will remain in pending, which is the correct
        # behaviour.
        $this->log->log([
            'type' => Log::TYPE_MESSAGE,
            'subtype' => $subject ? Log::SUBTYPE_REJECTED : Log::SUBTYPE_DELETED,
            'msgid' => $this->id,
            'groupid' => $groupid,
            'text' => $subject
        ]);

        $handled = false;

        $sql = "SELECT * FROM messages_groups WHERE msgid = ? AND groupid = ?;";
        $groups = $this->dbhr->preQuery($sql, [ $this->id, $groupid ]);
        foreach ($groups as $group) {
            if ($group['yahooreject']) {
                # We can trigger rejection by email - do so.
                $this->mailer($group['yahooreject'], "My name is Iznik and I reject this message", "", NULL, '-f' . MODERATOR_EMAIL);
                $handled = true;
            }

            if ($group['yahoopendingid']) {
                # We can trigger rejection via the plugin - do so.
                $p = new Plugin($this->dbhr, $this->dbhm);
                $p->add($groupid, [
                    'type' => 'RejectPendingMessage',
                    'id' => $group['yahoopendingid']
                ]);
                $handled = true;
            }
        }

        if ($handled) {
            $sql = "UPDATE messages_groups SET deleted = 1 WHERE msgid = ?;";
            $this->dbhm->preExec($sql, [
                $this->id
            ]);

            if ($subject) {
                # We have a rejection mail to send.
                $to = $this->getEnvelopefrom();
                $to = $to ? $to : $this->getFromaddr();
                $g = new Group($this->dbhr, $this->dbhm, $groupid);
                $me = whoAmI($this->dbhr, $this->dbhm);

                $name = $me->getName();
                $headers = "From: \"$name\" <" . $g->getModsEmail() . ">\r\n";

                $this->mailer(
                    $to,
                    $subject,
                    $body,
                    $headers,
                    "-f" . $g->getModsEmail()
                );
            }
        }
    }

    public function approve($groupid) {
        # No need for a transaction - if things go wrong, the message will remain in pending, which is the correct
        # behaviour.
        $this->log->log([
            'type' => Log::TYPE_MESSAGE,
            'subtype' => Log::SUBTYPE_APPROVED,
            'msgid' => $this->id,
            'groupid' => $groupid
        ]);

        $handled = false;

        $sql = "SELECT * FROM messages_groups WHERE msgid = ? AND groupid = ?;";
        $groups = $this->dbhr->preQuery($sql, [ $this->id, $groupid ]);
        foreach ($groups as $group) {
            if ($group['yahooapprove']) {
                # We can trigger approval by email - do so.
                $this->mailer($group['yahooapprove'], "My name is Iznik and I approve this message", "", NULL, '-f' . MODERATOR_EMAIL);
                $handled = true;
            }

            if ($group['yahoopendingid']) {
                # We can trigger approval via the plugin - do so.
                $p = new Plugin($this->dbhr, $this->dbhm);
                $p->add($groupid, [
                    'type' => 'ApprovePendingMessage',
                    'id' => $group['yahoopendingid']
                ]);
                $handled = true;
            }
        }

        if ($handled) {
            $sql = "UPDATE messages_groups SET collection = ? WHERE msgid = ?;";
            $this->dbhm->preExec($sql, [
                Collection::APPROVED,
                $this->id
            ]);
        }
    }

    function delete($reason = NULL, $groupid = NULL)
    {
        $rc = true;

        if ($this->attach_dir) {
            rrmdir($this->attach_dir);
        }

        if ($this->id) {
            $this->log->log([
                'type' => Log::TYPE_MESSAGE,
                'subtype' => Log::SUBTYPE_DELETED,
                'msgid' => $this->id,
                'text' => $reason,
                'groupid' => $groupid
            ]);

            if ($groupid) {
                # The message has been allocated to a group; mark it as deleted.  We keep deleted messages for
                # PD.
                $rc = $this->dbhm->preExec("UPDATE messages_groups SET deleted = 1 WHERE msgid = ? AND groupid = ?;", [
                    $this->id,
                    $groupid
                ]);

                # We might be deleting a pending or spam message, in which case it may also need rejecting on Yahoo.
                $sql = "SELECT * FROM messages_groups WHERE msgid = ? AND groupid = ?;";
                $groups = $this->dbhr->preQuery($sql, [ $this->id, $groupid ]);

                foreach ($groups as $group) {
                    if ($group['yahooreject']) {
                        # We can trigger rejection by email - do so.
                        $this->mailer($group['yahooreject'], "My name is Iznik and I reject this message", "", NULL, '-f' . MODERATOR_EMAIL);
                    }

                    if ($group['yahoopendingid']) {
                        # We can trigger rejection via the plugin - do so.
                        $p = new Plugin($this->dbhr, $this->dbhm);
                        $p->add($groupid, [
                            'type' => 'RejectPendingMessage',
                            'id' => $group['yahoopendingid']
                        ]);
                    }
                }
            } else {
                # The message has never reached a group.  We can fully deleted it.
                $rc = $this->dbhm->preExec("DELETE FROM messages WHERE id = ?;", [ $this->id ]);
            }
        }

        return($rc);
    }

    public function removeByMessageID($groupid) {
        # Try to find by message id.
        $msgid = $this->getMessageID();
        if ($msgid) {
            $this->dbhm->preExec("DELETE FROM messages WHERE messageid LIKE ? AND messages.id IN (SELECT msgid FROM messages_groups WHERE messages_groups.groupid = ?);", [
                $msgid,
                $groupid
            ]);
        }

        # Also try to find by TN post id
        $tnpostid = $this->getTnpostid();
        if ($tnpostid) {
            $this->dbhm->preExec("DELETE FROM messages WHERE tnpostid LIKE ? AND messages.id IN (SELECT msgid FROM messages_groups WHERE messages_groups.groupid = ?);", [
                $tnpostid,
                $groupid
            ]);
        }
    }

    /**
     * @return mixed
     */
    public function getFromuser()
    {
        return $this->fromuser;
    }
}