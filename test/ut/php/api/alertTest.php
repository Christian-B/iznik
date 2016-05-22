<?php

if (!defined('UT_DIR')) {
    define('UT_DIR', dirname(__FILE__) . '/../..');
}
require_once UT_DIR . '/IznikAPITestCase.php';
require_once IZNIK_BASE . '/include/user/User.php';
require_once IZNIK_BASE . '/include/group/Group.php';
require_once IZNIK_BASE . '/include/group/Alerts.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes disabled
 */
class alertAPITest extends IznikAPITestCase
{
    public $dbhr, $dbhm;

    protected function setUp()
    {
        parent::setUp();

        /** @var LoggedPDO $dbhr */
        /** @var LoggedPDO $dbhm */
        global $dbhr, $dbhm;
        $this->dbhr = $dbhr;
        $this->dbhm = $dbhm;

        $dbhm->preExec("DELETE FROM alerts WHERE subject LIKE 'UT %';");

        $u = new User($this->dbhr, $this->dbhm);
        $this->uid = $u->create(NULL, NULL, 'Test User');
        $this->user = new User($this->dbhr, $this->dbhm, $this->uid);
        assertGreaterThan(0, $this->user->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $u = new User($this->dbhr, $this->dbhm);
        $this->uid2 = $u->create(NULL, NULL, 'Test User');
        $this->user2 = new User($this->dbhr, $this->dbhm, $this->uid2);
        assertGreaterThan(0, $this->user2->addLogin(User::LOGIN_NATIVE, NULL, 'testpw'));

        $g = new Group($this->dbhr, $this->dbhm);
        $this->groupid = $g->create('testgroup', Group::GROUP_FREEGLE);
    }

    protected function tearDown()
    {
        parent::tearDown();
    }

    public function __construct()
    {
    }

    public function testBasic()
    {
        error_log(__METHOD__);

        $alertdata = [
            'groupid' => $this->groupid,
            'to' => 'Mods',
            'from' => 'geeks',
            'subject' => 'UT Alert',
            'text' => 'Please ignore this - generated by Iznik UT',
            'html' => '<p>Please ignore this - generated by Iznik UT</p>'
        ];

        # Can't create logged out.
        $ret = $this->call('alert', 'PUT', $alertdata);
        assertEquals(1, $ret['ret']);

        # Or logged in as non-support
        assertTrue($this->user->login('testpw'));
        $ret = $this->call('alert', 'PUT', $alertdata);
        assertEquals(1, $ret['ret']);

        # Can create as support.
        $this->user->setPrivate('systemrole', User::SYSTEMROLE_SUPPORT);
        $ret = $this->call('alert', 'PUT', $alertdata);
        error_log("Got result " . var_export($ret, TRUE));
        assertEquals(0, $ret['ret']);

        $ret = $this->call('alert', 'PUT', $alertdata);
        assertEquals(0, $ret['ret']);
        $id = $ret['id'];
        assertNotNull($id);

        # Should now be able to get it.
        $ret = $this->call('alert', 'GET', [ 'id' => $id ]);
        assertEquals(0, $ret['ret']);
        assertEquals($id, $ret['alert']['id']);
        assertTrue(array_key_exists('stats', $ret['alert']));

        foreach ($alertdata as $key => $val) {
            assertEquals($val, $ret['alert'][$key]);
        }

        # Add a mod
        $a = new Alert($this->dbhr, $this->dbhm);
        $this->user->addMembership($this->groupid, User::ROLE_MODERATOR);
        $this->user->addEmail('test-' . rand() . '@blackhole.io');
        assertEquals(2, $a->process($id));

        $this->user->setPrivate('systemrole', User::SYSTEMROLE_SUPPORT);
        $ret = $this->call('alert', 'GET', [ 'id' => $id ]);
        assertEquals(0, $ret['ret']);
        $stats = $ret['alert']['stats'];
        unset($stats['responses']['groups'][0]['group']);
        error_log("Stats " . var_export($stats, TRUE));
        assertEquals(array (
                      'sent' =>
                          array (
                              'mods' => 1,
                              'modemails' => 1,
                              'owneremails' => 1,
                          ),
                      'responses' =>
                          array (
                              'groups' =>
                                  array (
                                      0 =>
                                          array (
                                              'summary' =>
                                                  array (
                                                      'count' => 2,
                                                      'response' => 'none',
                                                  ),
                                          ),
                                  ),
                              'mods' =>
                                  array (
                                      'none' => 1,
                                      'reached' => 0
                                  ),
                              'modemails' =>
                                  array (
                                  ),
                              'owner' =>
                                  array (
                                      'none' => 1,
                                      'reached' => 0
                                  ),
                          )
                    ), $stats);

        $tracks = $this->dbhr->preQuery("SELECT id FROM alerts_tracking WHERE alertid = ?;", [ $id ]);
        $a->beacon($tracks[0]['id']);

        $ret = $this->call('alert', 'POST', [
            'action' => 'clicked',
            'trackid' => $tracks[1]['id']
        ]);
        assertEquals(0, $ret['ret']);

        $ret = $this->call('alert', 'GET', []);
        assertEquals(0, $ret['ret']);
        $found = FALSE;
        foreach ($ret['alerts'] as $alert) {
            if ($id == $alert['id']) {
                $found = TRUE;
            }
        }

        assertTrue($found);

        $ret = $this->call('alert', 'GET', [ 'id' => $id ]);
        assertEquals(0, $ret['ret']);
        $stats = $ret['alert']['stats'];
        unset($stats['responses']['groups'][0]['group']);
        error_log("Stats 2 " . var_export($stats, TRUE));
        assertEquals(array (
            'sent' =>
                array (
                    'mods' => 1,
                    'modemails' => 1,
                    'owneremails' => 1,
                ),
            'responses' =>
                array (
                    'groups' =>
                        array (
                            0 =>
                                array (
                                    'summary' =>
                                        array (
                                            'count' => 1,
                                            'response' => 'Read',
                                        ),
                                ),
                        ),
                    'mods' =>
                        array (
                            'none' => 0,
                            'reached' => 1
                        ),
                    'modemails' =>
                        array (
                        ),
                    'owner' =>
                        array (
                            'none' => 0,
                            'reached' => 1
                        ),
                )
        ), $stats);        

        error_log(__METHOD__ . " end");
    }
}