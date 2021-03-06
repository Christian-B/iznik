define([
    'jquery',
    'underscore',
    'backbone',
    'iznik/base',
    'iznik/models/message',
    'iznik/models/user/search',
    'iznik/views/group/communityevents',
    'iznik/views/pages/pages',
    'iznik/views/user/message'
], function($, _, Backbone, Iznik) {
    Iznik.Views.User.Pages.Home = Iznik.Views.Page.extend({
        template: "user_home_main",

        filter: function(model) {
            // Only show a search result for an offer which has not been taken or wanted not received.
            var thetype = model.get('type');
            var paired = _.where(model.get('related'), {
                type: thetype == 'Offer' ? 'Taken' : 'Received'
            });

            return (paired.length == 0);
        },

        fetchedChats: function() {
            var self = this;

            // This can be called twice - once with cached data, once with the update.
            if (!self.chatsFetched) {
                // Set up the whole caboodle.
                self.chatsFetched = true;

                var v = new Iznik.Views.Help.Box();
                v.template = 'user_home_homehelp';
                v.render().then(function (v) {
                    self.$('.js-homehelp').html(v.el);
                });

                var v = new Iznik.Views.Help.Box();
                v.template = 'user_home_offerhelp';
                v.render().then(function (v) {
                    self.$('.js-offerhelp').html(v.el);
                });

                // It's quicker to get all our messages in a single call.  So we have two CollectionViews, one for offers,
                // one for wanteds.
                self.offers = new Iznik.Collection();
                self.wanteds = new Iznik.Collection();

                self.offersView = new Backbone.CollectionView({
                    el: self.$('.js-offers'),
                    modelViewOptions: {
                        offers: self.offers,
                        page: self,
                        chatid: self.options.chatid
                    },
                    modelView: Iznik.Views.User.Home.Offer,
                    collection: self.offers,
                    visibleModelsFilter: self.filter
                });

                self.offersView.render();

                self.wantedsView = new Backbone.CollectionView({
                    el: self.$('.js-wanteds'),
                    modelView: Iznik.Views.User.Home.Wanted,
                    modelViewOptions: {
                        wanteds: self.wanteds,
                        page: self,
                        chatid: self.options.chatid
                    },
                    collection: self.wanteds,
                    visibleModelsFilter: self.filter
                });

                self.wantedsView.render();

                // We want to get all messages we've sent.  From the user pov we don't distinguish in
                // how they look.  This is because most messages are approved and there's no point worrying them, and
                // provoking "why hasn't it been approved yet" complaints.
                self.messages = new Iznik.Collections.Message(null, {
                    modtools: false,
                    collection: 'AllUser',
                    type: 'Freegle'
                });

                var count = 0;

                // We listen for events on the messages collection and ripple them through to the relevant offers/wanteds
                // collection.  CollectionView will then handle rendering/removing the messages view.
                self.listenTo(self.messages, 'add', function (msg) {
                    var related = msg.get('related');

                    if (msg.get('type') == 'Offer') {
                        var taken = _.where(related, {
                            type: 'Taken'
                        });

                        if (taken.length == 0) {
                            self.offers.add(msg);
                        }
                    } else if (msg.get('type') == 'Wanted') {
                        var received = _.where(related, {
                            type: 'Received'
                        });

                        if (received.length == 0) {
                            self.wanteds.add(msg);
                        }
                    } else {
                        console.log("Got something else", msg);
                    }
                });

                self.listenTo(self.messages, 'remove', function (msg) {
                    if (msg.get('type') == 'Offer') {
                        self.offers.remove(msg);
                    } else if (msg.get('type') == 'Wanted') {
                        self.wanteds.remove(msg);
                    }
                });

                // Now get the messages.
                var cb = _.bind(self.fetchedMessages, self);
                self.messages.fetch({
                    cached: cb,
                    cacheFetchAfter: 2,
                    data: {
                        fromuser: Iznik.Session.get('me').id,
                        types: ['Offer', 'Wanted'],
                        limit: 100
                    }
                }).then(cb);
            } else {
                // Rerender the messages in case they have now changed.
                self.offersView.render();
                self.wantedsView.render();
            }

            // We might have now found out that something which was in our cache is taken/received and should
            // therefore no longer show.  Refresh.
            self.offersView.reapplyFilter('visibleModels');
            self.wantedsView.reapplyFilter('visibleModels');
        },

        fetchedMessages: function () {
            var self = this;

            if (self.offers.length == 0) {
                self.$('.js-nooffers').fadeIn('slow');
            } else {
                self.$('.js-nooffers').hide();
            }

            // We might have now found out that something which was in our cache is taken/received and should
            // therefore no longer show.  Refresh.
            self.offersView.reapplyFilter('visibleModels');
            self.wantedsView.reapplyFilter('visibleModels');
        },

        render: function () {
            var self = this;

            Iznik.Session.askSubscription();

            var p = Iznik.Views.Page.prototype.render.call(this, {
                noSupporters: true
            });

            p.then(function(self) {
                // Left menu is community events
                var v = new Iznik.Views.User.CommunityEventsSidebar();
                v.render().then(function () {
                    $('#js-eventcontainer').append(v.$el);
                });

                // Searches
                self.searches = new Iznik.Collections.User.Search();

                self.searchView = new Backbone.CollectionView({
                    el: self.$('.js-searchlist'),
                    modelView: Iznik.Views.User.Home.Search,
                    collection: self.searches
                });

                self.searchView.render();

                var cb = _.bind(function() {
                    if (!this.fetchedSearches) {
                        this.fetchedSearches = true;
                        if (this.searches.length > 0) {
                            this.$('.js-searchrow').fadeIn('slow');
                        }
                    }
                }, self);

                self.searches.fetch({
                    cached: cb
                }).then(cb);

                // We need the chats, as they are used when displaying messages.
                var cb = _.bind(self.fetchedChats, self);
                Iznik.Session.chats.fetch({
                    cached: cb
                }).then(cb);

                if (Iznik.Session.get('me').bouncing) {
                    self.$('.js-bouncing .js-email').html(Iznik.Session.get('me').email);
                    self.$('.js-bouncing').fadeIn('slow');
                }
            });

            return(p);
        }
    });

    Iznik.Views.User.Pages.MyPost = Iznik.Views.Page.extend({
        template: "user_home_mypost",

        fetchedChats: function() {
            var self = this;
            console.log("Fetched chats", Iznik.Session.chats.length);

            // This can be called twice - once with cached data, once with the update.
            if (!self.chatsFetched) {
                self.chatsFetched = true;

                self.model = new Iznik.Models.Message({
                    id: self.options.id
                });

                self.model.fetch().then(function() {
                    var v;

                    if (self.model.get('type') == 'Offer') {
                        v = new Iznik.Views.User.Home.Offer({
                            model: self.model
                        });
                    } else if (self.model.get('type') == 'Offer') {
                        v = new Iznik.Views.User.Home.Wanted({
                            model: self.model
                        });
                    }

                    v.expanded = true;

                    v.render().then(function() {
                        self.$('.js-post').html(v.$el);
                        self.$('.js-continue').fadeIn('slow');
                    })
                });
            }
        },

        render: function () {
            var self = this;

            Iznik.Session.askSubscription();

            var p = Iznik.Views.Page.prototype.render.call(this, {
                noSupporters: true
            });

            p.then(function(self) {
                // Left menu is community events
                var v = new Iznik.Views.User.CommunityEventsSidebar();
                v.render().then(function () {
                    $('#js-eventcontainer').append(v.$el);
                });

                // We need the chats, as they are used when displaying messages.
                var cb = _.bind(self.fetchedChats, self);
                Iznik.Session.chats.fetch().then(cb);
            });

            return(p);
        }
    });

    Iznik.Views.User.Home.Offer = Iznik.Views.User.Message.extend({
        template: "user_home_offer",

        events: {
            'click .js-taken': 'taken',
            'click .js-withdraw': 'withdrawn'
        },

        taken: function () {
            this.outcome('Taken');
        },

        withdrawn: function () {
            this.outcome('Withdrawn');
        },

        outcome: function (outcome) {
            var self = this;

            var v = new Iznik.Views.User.Outcome({
                model: this.model,
                outcome: outcome
            });

            self.listenToOnce(v, 'outcame', function () {
                self.$el.fadeOut('slow', function () {
                    self.destroyIt();
                });
            })

            v.render();
        }
    });

    Iznik.Views.User.Home.Wanted = Iznik.Views.User.Message.extend({
        template: "user_home_wanted",

        events: {
            'click .js-received': 'received',
            'click .js-withdraw': 'withdrawn'
        },

        received: function () {
            this.outcome('Received');
        },

        withdrawn: function () {
            this.outcome('Withdrawn');
        },

        outcome: function (outcome) {
            var self = this;

            var v = new Iznik.Views.User.Outcome({
                model: this.model,
                outcome: outcome
            });

            self.listenToOnce(v, 'outcame', function () {
                self.$el.fadeOut('slow', function () {
                    self.destroyIt();
                });
            })

            v.render();
        }
    });

    Iznik.Views.User.Outcome = Iznik.Views.Modal.extend({
        template: 'user_home_outcome',

        events: {
            'click .js-confirm': 'confirm',
            'change .js-outcome': 'changeOutcome',
            'click .btn-radio .btn': 'click'
        },

        changeOutcome: function () {
            if (this.$('.js-outcome').val() == 'Withdrawn') {
                this.$('.js-user').addClass('reallyHide');
            } else {
                this.$('.js-user').removeClass('reallyHide');
            }

            this.defaultText();
        },

        defaultText: function() {
            var text;

            switch (this.$('.js-outcome').val()) {
                case 'Taken': text = 'Thanks, this has now been taken.'; break;
                case 'Received': text = 'Thanks, this has now been received.'; break;
                case 'Withdrawn': text = 'Sorry, this is no longer available.'; break;
            }

            self.$('.js-comment').val(text);
        },

        click: function (ev) {
            $('.btn-radio .btn').removeClass('active');
            var btn = $(ev.currentTarget);
            btn.addClass('active');

            if (btn.hasClass('js-unhappy')) {
                this.$('.js-public').hide();
                this.$('.js-private').fadeIn('slow');
            } else {
                this.$('.js-private').hide();
                this.$('.js-public').fadeIn('slow');
            }

            this.defaultText();
        },

        confirm: function () {
            var self = this;
            var outcome = self.$('.js-outcome').val();
            var comment = self.$('.js-comment').val().trim();
            comment = comment.length > 0 ? comment : null;
            var happiness = null;
            var selbutt = self.$('.btn.active');
            var userid = self.$('.js-user').val();

            if (selbutt.length > 0) {
                if (selbutt.hasClass('js-happy')) {
                    happiness = 'Happy';
                } else if (selbutt.hasClass('js-unhappy')) {
                    happiness = 'Unhappy';
                } else {
                    happiness = 'Fine';
                }
            }

            $.ajax({
                url: API + 'message/' + self.model.get('id'),
                type: 'POST',
                data: {
                    action: 'Outcome',
                    outcome: outcome,
                    happiness: happiness,
                    comment: comment,
                    userid: userid
                }, success: function (ret) {
                    if (ret.ret === 0) {
                        self.close();
                        self.trigger('outcame');

                        var v = new Iznik.Views.Modal();
                        v.template = 'user_home_supportus';
                        v.render();
                    }
                }
            })
        },

        render: function () {
            var self = this;
            this.model.set('outcome', this.options.outcome);
            var p = this.open(this.template);

            p.then(function() {
                self.changeOutcome();

                // We want to show the people to whom it's promised first, as they're likely to be correct and most
                // likely to make the user change it if they're not correct.
                var replies = self.model.get('replies');
                replies = _.sortBy(replies, function (reply) {
                    return (-reply.promised);
                });
                _.each(replies, function (reply) {
                    self.$('.js-user').append('<option value="' + reply.user.id + '" />');
                    self.$('.js-user option:last').html(reply.user.displayname);
                })
                self.$('.js-user').append('<option value="0">Someone else</option>');
            });

            return(p);
        }
    });

    Iznik.Views.User.Home.Search = Iznik.View.extend({
        template: "user_home_search",

        tagName: 'li',

        events: {
            'click .js-delete': 'delete',
            'click .js-search': 'search'
        },

        search: function() {
            Router.navigate('/find/search/' + encodeURIComponent(this.model.get('term')), true);
        },

        delete: function() {
            var self = this;
            $.ajax({
                url: API + 'usersearch',
                type: 'DELETE',
                data: {
                    id: self.model.get('id')
                },
                success: function(ret) {
                    if (ret.ret == 0) {
                        self.$el.fadeOut('slow');
                    }
                }
            });
        }
    });
});