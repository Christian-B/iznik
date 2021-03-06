define([
    'jquery',
    'underscore',
    'backbone',
    'iznik/base',
    'iznik/views/chat/chat',
    'iznik/events'
], function($, _, Backbone, Iznik, ChatHolder, monitor) {
    // We have a view for everything that is common across all pages, e.g. sidebars.
    var currentPage = null;

    function logout() {
        try {
            // We might be signed in to Google.  Make sure we're not.
            gapi.auth.signOut();
            console.log("Google signed out");
            var GoogleLoad = new Iznik.Views.GoogleLoad();
            GoogleLoad.disconnectUser();
            console.log("Google access token revoked");
        } catch (e) {
            console.log("Google signout failed", e);
        };

        $.ajax({
            url: API + 'session',
            type: 'POST',
            headers: {
                'X-HTTP-Method-Override': 'DELETE'
            },
            complete: function () {
                // Zap our session cache - we're no longer logged in.
                try {
                    localStorage.removeItem('session');
                } catch (e) {
                }

                // Force reload of window to clear any data.
                window.location = window.location.protocol + '//' + window.location.host;
            }
        })
    }

    Iznik.Views.Page = Iznik.View.extend({
        modtools: false,
        
        footer: false,

        home: function () {
            var homeurl = this.modtools ? '/modtools' : '/';

            if (window.location.pathname == homeurl) {
                // Reload - this is because clicking on this when we're already on it can mean that something's 
                // broken and they're confused.
                window.location.reload();
            } else {
                Router.navigate(homeurl, true);
            }
        },

        signin: function () {
            var sign = new Iznik.Views.SignInUp({
                modtools: this.modtools
            });
            sign.render();
        },

        logout: function() {
            logout();
        },

        render: function (options) {
            var self = this;

            // Start event tracking.
            if (monitor) {
                monitor.start();
            }

            if (currentPage) {
                // We have previous rendered a page.  Kill that off, so that it is not listening for events and
                // messing about with the DOM.
                currentPage.remove();
            }

            currentPage = self;

            // Record whether we are showing a user or ModTools page.
            Iznik.Session.set('modtools', self.modtools);

            options = typeof options == 'undefined' ? {} : options;

            var rightbar = null;
            var rightaccordion = $('#rightaccordion');

            if (rightaccordion.length > 0) {
                // We render the right sidebar only once, so that the plugin work remains there if we route to a new page
                rightbar = rightaccordion.children().detach();
            }

            // Set the base page layout.
            var p = new Promise(function(resolve, reject) {
                templateFetch(self.modtools ? 'modtools_layout_layout' : 'user_layout_layout').then(function(tpl) {
                    if (self.title) {
                        window.document.title = self.title;
                    }

                    $('#bodyContent').html(window.template(tpl));
                    $('.js-pageContent').html(self.$el);

                    ChatHolder().createMinimised();

                    $('#botleft').empty();

                    if (self.modtools) {
                        // ModTools menu and sidebar.
                        new Iznik.Views.ModTools.LeftMenu().render().then(function(m) {
                            $('.js-leftsidebar').html(m.el);
                        });

                        rightaccordion = $('#rightaccordion');

                        if (!rightbar) {
                            var s = new Iznik.Views.Supporters();
                            s.render().then(function(s) {
                                rightaccordion.append(s.el);

                                require(['iznik/accordionpersist', 'iznik/views/plugin'], function() {
                                    window.IznikPlugin = new Iznik.Views.Plugin.Main();
                                    IznikPlugin.render().then(function(v) {
                                        rightaccordion.append(v.el);
                                    })
                                    rightaccordion.accordionPersist();
                                });
                            });
                        } else {
                            rightaccordion.empty().append(rightbar);
                        }

                        if (options.noSupporters) {
                            $('.js-supporters').hide();
                        } else {
                            $('.js-supporters').show();
                        }
                    } else {
                        if (!self.footer) {
                            // Put bottom left links in.
                            var v = new Iznik.Views.User.Botleft();
                            v.render();
                            $('#botleft').append(v.$el);
                        }

                        var v = new Iznik.Views.User.Social();
                        v.render();
                        $('#botleft').append(v.$el);

                        // Highlight current page if any.
                        $('#navbar-collapse a').each(function () {
                            var href = $(this).attr('href');
                            $(this).closest('li').removeClass('active');

                            if (href == window.location.pathname) {
                                $(this).closest('li').addClass('active');

                                // Force reload on click, which doesn't happen by default.
                                $(this).click(function () {
                                    Backbone.history.loadUrl(href);
                                });
                            }
                        });
                    }

                    if (self.options.naked) {
                        // We don't want the page framing.   This is used for plugins.
                        $('.navbar, .js-leftsidebar, .js-rightsidebar').hide();
                        $('.margtopbig').removeClass('margtopbig');
                        $('#botleft').hide();
                    }

                    // Put self page in.  Need to know whether we're logged in first, in order to start the
                    // chats, which some pages may rely on behing active.
                    self.listenToOnce(Iznik.Session, 'isLoggedIn', function (loggedIn) {
                        if (loggedIn) {
                            if (!Iznik.Session.get('me').email) {
                                // We have no email.  This can happen for some login types.  Force them to provide one.
                                require(["iznik/views/pages/user/settings"], function() {
                                    var v = new Iznik.Views.User.Pages.Settings.NoEmail();
                                    v.render();
                                });
                            } else {
                                // Since we're logged in, we can start chat.
                                ChatHolder({
                                    modtools: self.modtools
                                }).render();
                            }
                        }

                        templateFetch(self.template).then(function(tpl) {
                            if (self.model) {
                                self.$el.html(window.template(tpl)(self.model.toJSON2()));
                            } else {
                                // Default is that we pass the session as the model.
                                self.$el.html(window.template(tpl)(Iznik.Session.toJSON2()));
                            }

                            $('.js-pageContent').html(self.$el);

                            $('#footer').remove();

                            if (self.footer) {
                                var v = new Iznik.Views.Page.Footer();
                                v.render().then(function() {
                                    $('body').addClass('Site');
                                    $('body').append(v.$el);
                                });
                            }

                            // Show anything which should or shouldn't be visible based on login status.
                            var loggedInOnly = $('.js-loggedinonly');
                            var loggedOutOnly = $('.js-loggedoutonly');

                            if (!self.modtools && !self.noback) {
                                // For user pages, we add our background if we're logged in.
                                $('body').addClass('bodyback');
                            } else {
                                $('body').removeClass('bodyback');
                            }

                            if (loggedIn) {
                                loggedInOnly.removeClass('reallyHide');
                                loggedOutOnly.addClass('reallyHide');
                            } else {
                                loggedOutOnly.removeClass('reallyHide');
                                loggedInOnly.addClass('reallyHide');
                            }

                            // Sort out any menu
                            $("#menu-toggle").click(function (e) {
                                e.preventDefault();
                                $("#wrapper").toggleClass("toggled");
                            });

                            window.scrollTo(0, 0);

                            // Let anyone who cares know.
                            self.trigger('pageContentAdded');

                            // This doesn't work as an event as it's outwith our element, so attach manually.
                            if (self.home) {
                                $('#bodyContent .js-home').click(_.bind(self.home, self));
                            }

                            if (self.signin) {
                                $('#bodyContent .js-signin').click(_.bind(self.signin, self));
                            }

                            $('.js-logout').click(function() {
                                logout();
                            });

                            // Now that we're in the DOM, ensure events work.
                            self.delegateEvents();

                            resolve(self);
                        });
                    });

                    Iznik.Session.testLoggedIn();
                });
            });

            return(p);
        }
    });

    Iznik.Views.LocalStorage = Iznik.Views.Page.extend({
        template: "localstorage"
    });

    Iznik.Views.User.Pages.NotFound = Iznik.Views.Page.extend({
        template: "notfound"
    });

    Iznik.Views.ModTools.LeftMenu = Iznik.View.extend({
        template: "layout_leftmenu",

        events: {
            'click .js-logout': 'logout'
        },

        logout: function () {
            logout();
        },

        render: function () {
            var p = Iznik.View.prototype.render.call(this);
            p.then(function(self) {
                // Bypass caching for plugin load
                self.$('.js-firefox').attr('href',
                    self.$('.js-firefox').attr('href') + '?' + Math.random()
                );

                // Highlight current page if any.
                self.$('a').each(function () {
                    var href = $(this).attr('href');
                    $(this).closest('li').removeClass('active');

                    if (href == window.location.pathname) {
                        $(this).closest('li').addClass('active');

                        // Force reload on click, which doesn't happen by default.
                        $(this).click(function () {
                            Backbone.history.loadUrl(href);
                        });
                    }
                });

                if (Iznik.Session.isAdminOrSupport()) {
                    self.$('.js-adminsupportonly').removeClass('hidden');
                }

                if (Iznik.Session.isAdmin()) {
                    self.$('.js-adminonly').removeClass('hidden');
                }

                // We need to create a hidden signin button because otherwise the Google logout method doesn't
                // work properly.  See http://stackoverflow.com/questions/19353034/how-to-sign-out-using-when-using-google-sign-in/19356354#19356354
                var GoogleLoad = new Iznik.Views.GoogleLoad();
                GoogleLoad.buttonShim('googleshim');
            });

            return p;
        }
    });

    Iznik.Views.Supporters = Iznik.View.extend({
        className: "panel panel-default js-supporters",

        template: "layout_supporters",

        render: function () {
            var p = Iznik.View.prototype.render.call(this);
            p.then(function (self) {
                $.ajax({
                    url: API + 'supporters',
                    success: function (ret) {
                        var html = '';
                        _.each(ret.supporters.Wowzer, function (el, index, list) {
                            if (index == ret.supporters.Wowzer.length - 1) {
                                html += ' and '
                            } else if (index > 0) {
                                html += ', '
                            }

                            html += el.name;
                        });
                        self.$('.js-wowzer').html(html);

                        var html = '';
                        _.each(ret.supporters['Front Page'], function (el, index, list) {
                            if (index == ret.supporters['Front Page'].length - 1) {
                                html += ' and '
                            } else if (index > 0) {
                                html += ', '
                            }

                            html += el.name;
                        });
                        self.$('.js-frontpage').html(html);

                        self.$('.js-content').fadeIn('slow');
                    }
                });
            });

            return(p);
        }
    });

    Iznik.Views.ModTools.Pages.Supporters = Iznik.Views.Page.extend({
        modtools: true,

        template: "supporters",

        render: function () {
            var self = this;

            Iznik.Views.Page.prototype.render.call(this, {
                noSupporters: true
            }).then(function() {
                $.ajax({
                    url: API + 'supporters',
                    success: function (ret) {
                        var html = '';

                        function add(el, index, list) {
                            if (html) {
                                html += ', '
                            }

                            html += el.name;
                        }

                        _.each(ret.supporters['Wowzer'], add);
                        _.each(ret.supporters['Front Page'], add);
                        _.each(ret.supporters['Supporter'], add);

                        self.$('.js-list').html(html);
                        self.$('.js-content').fadeIn('slow');
                    }
                });
            });
        }
    });

    Iznik.Views.Page.Footer = Iznik.View.extend({
        id: 'footer',
        tagName: 'footer',
        className: 'footer',
        template: 'footer'
    });

    Iznik.Views.User.Botleft = Iznik.View.extend({
        className: 'padleft hidden-sm hidden-xs',
        template: 'user_botleft'
    });
    
    Iznik.Views.User.Social = Iznik.View.extend({
        id: 'social',
        className: 'padleft hidden-sm hidden-xs',
        template: 'user_social'
    })    
});