<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikAPITest.php';
require_once IZNIK_BASE . '/include/user/User.php';
require_once IZNIK_BASE . '/include/group/Group.php';
require_once IZNIK_BASE . '/include/mail/MailRouter.php';
require_once IZNIK_BASE . '/include/message/MessageCollection.php';
require_once IZNIK_BASE . '/include/misc/Location.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class locationsAPITest extends IznikAPITest {
    public $dbhr, $dbhm;

    protected function setUp() {
        parent::setUp ();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM users WHERE fullname = 'Test User';");
        $dbhm->preExec("DELETE FROM users WHERE id in (SELECT userid FROM users_emails WHERE email IN ('test@test.com', 'test2@test.com'));");
        $dbhm->preExec("DELETE FROM groups WHERE nameshort = 'testgroup';");
        $dbhm->preExec("DELETE FROM users WHERE yahooUserId = 1;");
        $dbhm->preExec("DELETE FROM messages_history WHERE fromaddr = 'test@test.com';");

        # We test around Tuvalu.  If you're setting up Tuvalu Freegle you may need to change that.
        $dbhm->preExec("DELETE FROM locations_grids WHERE swlat >= 8.3 AND swlat <= 8.7;");
        $dbhm->preExec("DELETE FROM locations_grids WHERE swlat >= 179.1 AND swlat <= 179.3;");
        $dbhm->preExec("DELETE FROM locations WHERE name LIKE 'Tuvalu%';");
        $dbhm->preExec("DELETE FROM locations WHERE name LIKE '??%';");
        for ($swlat = 8.3; $swlat <= 8.6; $swlat += 0.1) {
            for ($swlng = 179.1; $swlng <= 179.3; $swlng += 0.1) {
                $nelat = $swlat + 0.1;
                $nelng = $swlng + 0.1;

                # Use lng, lat order for geometry because the OSM data uses that.
                $dbhm->preExec("INSERT IGNORE INTO locations_grids (swlat, swlng, nelat, nelng, box) VALUES (?, ?, ?, ?, GeomFromText('POLYGON(($swlng $swlat, $nelng $swlat, $nelng $nelat, $swlng $nelat, $swlng $swlat))'));",
                    [
                        $swlat,
                        $swlng,
                        $nelat,
                        $nelng
                    ]);
            }
        }

        $this->group = new Group($this->dbhr, $this->dbhm);
        $this->groupid = $this->group->create('testgroup', Group::GROUP_REUSE);

        $u = new User($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->user = new User($this->dbhr, $this->dbhm, $this->uid);
        $this->user->addEmail('test@test.com');
        assertEquals(1, $this->user->addMembership($this->groupid));
        assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));
    }

    protected function tearDown() {
        parent::tearDown ();
    }

    public function __construct() {
    }

    public function testPost() {
        error_log(__METHOD__);

        # Create two locations
        $l = new Location($this->dbhr, $this->dbhm);
        $lid1 = $l->create(NULL, 'Tuvalu High Street', 'Road', 'POINT(179.2167 8.53333)');
        $lid2 = $l->create(NULL, 'Tuvalu Hugh Street', 'Road', 'POINT(179.2167 8.53333)');

        # Create a group there
        $this->group->setPrivate('lat', 8.5);
        $this->group->setPrivate('lng', 179.3);

        # Create a message which should have the first subject suggested.
        $msg = file_get_contents('msgs/basic');
        $msg = str_ireplace('freegleplayground', 'testgroup', $msg);
        $msg = str_ireplace('Basic test', 'OFFER: Test (Tuvalu High Street)', $msg);

        $r = new MailRouter($this->dbhr, $this->dbhm);
        $id = $r->received(Message::YAHOO_PENDING, 'from@test.com', 'to@test.com', $msg);
        $rc = $r->route();
        assertEquals(MailRouter::PENDING, $rc);

        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals('OFFER: Test (Tuvalu High Street)', $m->getSubject());
        $atts = $m->getPublic(FALSE, FALSE, TRUE);
        assertEquals('OFFER: Test (Tuvalu High Street)', $atts['suggestedsubject']);

        # Now block that subject from this group.

        # Shouldn't be able to do this as a member
        assertTrue($this->user->login('testpw'));
        $ret = $this->call('locations', 'POST', [
            'id' => $lid1,
            'groupid' => $this->groupid,
            'messageid' => $id,
            'action' => 'Exclude'
        ]);
        assertEquals(2, $ret['ret']);

        $this->user->setRole(User::ROLE_MODERATOR, $this->groupid);
        $ret = $this->call('locations', 'POST', [
            'id' => $lid1,
            'groupid' => $this->groupid,
            'messageid' => $id,
            'action' => 'Exclude',
            'dup' => 2
        ]);
        assertEquals(0, $ret['ret']);

        # Get the message back - should have suggested the other one this time.
        $m = new Message($this->dbhr, $this->dbhm, $id);
        assertEquals('OFFER: Test (Tuvalu High Street)', $m->getSubject());
        $atts = $m->getPublic(FALSE, FALSE, TRUE);
        assertEquals('OFFER: Test (Tuvalu Hugh Street)', $atts['suggestedsubject']);

        error_log(__METHOD__ . " end");
    }

}
