Iznik.Views.ModTools.Pages.Settings = Iznik.Views.Page.extend({
    modtools: true,

    template: "modtools_settings_main",

    events: {
        'change .js-configselect': 'configSelect'
    },

    settingsGroup: function() {
        var self = this;

        // Because we switch the form based on our group select we need to remove old events to avoid saving new
        // changes to the previous group.
        if (self.myGroupForm) {
            self.myGroupForm.undelegateEvents();
        }
        if (self.groupForm) {
            self.groupForm.undelegateEvents();
        }

        if (self.selected > 0) {
            var group = new Iznik.Models.Group({
                id: self.selected
            });

            group.fetch().then(function() {
                var mysettings = group.get('mysettings');
                console.log("mysettings", mysettings);
                var configoptions = [];
                var configs = Iznik.Session.get('configs');
                configs.each(function(config) {
                    configoptions.push({
                        label: config.get('name'),
                        value: config.get('id')
                    });
                });
                self.myGroupModel = new IznikModel(mysettings);
                self.myGroupFields = [
                    {
                        name: 'configid',
                        label: 'ModConfig to use for this Group',
                        control: 'select',
                        options: configoptions
                    },
                    {
                        name: 'showmessages',
                        label: 'Show messages in All Groups?',
                        control: 'radio',
                        extraClasses: [ 'row' ],
                        options: [{label: 'Yes', value: 1}, {label: 'No', value:0 }]
                    },
                    {
                        name: 'showmembers',
                        label: 'Show members in All Groups?',
                        control: 'radio',
                        extraClasses: [ 'row' ],
                        options: [{label: 'Yes', value: 1}, {label: 'No', value:0 }]
                    },
                    {
                        control: 'button',
                        label: 'Save changes',
                        type: 'submit',
                        extraClasses: [ 'btn-success topspace botspace' ]
                    }
                ];

                self.myGroupForm = new Backform.Form({
                    el: $('#mygroupform'),
                    model: self.myGroupModel,
                    fields: self.myGroupFields,
                    events: {
                        'submit': function(e) {
                            // Send a PATCH to the server for mysettings.
                            e.preventDefault();
                            var newdata = self.myGroupModel.toJSON();
                            group.save({
                                'mysettings': newdata
                            }, { patch: true });
                            return(false);
                        }
                    }
                });

                self.myGroupForm.render();

                self.groupModel = new IznikModel(group.get('settings'));

                if (!self.groupModel.get('map')) {
                    self.groupModel.set('map', {
                        'zoom' : 12
                    });
                }

                self.groupFields = [
                    {
                        name: 'autoapprove.members',
                        label: 'Auto-approve pending members?',
                        control: 'radio',
                        options: [{label: 'Yes', value: 1}, {label: 'No', value:0 }]
                    },
                    {
                        name: 'duplicates.check',
                        label: 'Flag duplicate messages?',
                        control: 'radio',
                        options: [{label: 'Yes', value: 1}, {label: 'No', value:0 }]
                    },
                    {
                        name: 'keywords.offer',
                        label: 'OFFER keyword',
                        control: 'input'
                    },
                    {
                        name: 'keywords.taken',
                        label: 'TAKEN keyword',
                        control: 'input'
                    },
                    {
                        name: 'keywords.wanted',
                        label: 'WANTED keyword',
                        control: 'input'
                    },
                    {
                        name: 'keywords.received',
                        label: 'RECEIVED keyword',
                        control: 'input'
                    },
                    {
                        name: 'duplicates.offer',
                        label: 'OFFER duplicate period',
                        control: 'input',
                        type: 'number'
                    },
                    {
                        name: 'duplicates.taken',
                        label: 'TAKEN duplicate period',
                        control: 'input',
                        type: 'number'
                    },
                    {
                        name: 'duplicates.wanted',
                        label: 'WANTED duplicate period',
                        control: 'input',
                        type: 'number'
                    },
                    {
                        name: 'duplicates.received',
                        label: 'RECEIVED duplicate period',
                        control: 'input',
                        type: 'number'
                    },
                    {
                        name: 'map.zoom',
                        label: 'Default zoom for maps',
                        control: 'input',
                        type: 'number'
                    },
                    {
                        control: 'button',
                        label: 'Save changes',
                        type: 'submit',
                        extraClasses: [ 'btn-success topspace botspace' ]
                    }
                ];

                self.groupForm = new Backform.Form({
                    el: $('#groupform'),
                    model: self.groupModel,
                    fields: self.groupFields,
                    events: {
                        'submit': function(e) {
                            e.preventDefault();
                            var newdata = self.groupModel.toJSON();
                            group.save({
                                'settings': newdata
                            }, { patch: true });
                            return(false);
                        }
                    }
                });

                self.groupForm.render();

                // Layout messes up a bit for radio buttons.
                self.groupForm.$(':radio').closest('.form-group').addClass('clearfix');
            });
        }
    },

    configSelect: function() {
        var self = this;

        // Because we switch the form based on our config select we need to remove old events to avoid saving new
        // changes to the previous config.
        if (self.modConfigFormGeneral) {
            self.modConfigFormGeneral.undelegateEvents();
        }

        var selected = self.$('.js-configselect').val();
        console.log("configSelect", selected);

        if (selected > 0) {
            self.modConfigModel = new Iznik.Models.ModConfig({
                id: selected
            });

            self.modConfigModel.fetch().then(function() {
                self.modConfigFieldsGeneral = [
                    {
                        name: 'name',
                        label: 'ModConfig name',
                        control: 'input',
                        helpMessage: 'If you want to change the name of the ModConfig, edit it in here.'
                    },
                    {
                        name: 'fromname',
                        label: '"From:" name in messages',
                        control: 'select',
                        options: [{label: 'My name', value: 'My display name (above)'}, {label: '$groupname Moderator', value: 'Groupname Moderator' }]
                    },
                    {
                        name: 'coloursubj',
                        label: 'Colour-code subjects?',
                        control: 'select',
                        options: [{label: 'Yes', value: 1}, {label: 'No', value: 0 }]
                    },
                    {
                        name: 'subjreg',
                        label: 'Regular expression for colour-coding',
                        control: 'input'
                    },
                    {
                        name: 'subjlen',
                        label: 'Subject length warning',
                        control: 'input',
                        type: 'number'
                    },
                    {
                        name: 'network',
                        label: 'Network name for $network substitution string',
                        control: 'input'
                    },
                    {
                        control: 'button',
                        label: 'Save changes',
                        type: 'submit',
                        extraClasses: [ 'btn-success topspace botspace' ]
                    }
                ];

                self.modConfigFormGeneral = new Backform.Form({
                    el: $('#modconfiggeneral'),
                    model: self.modConfigModel,
                    fields: self.modConfigFieldsGeneral,
                    events: {
                        'submit': function(e) {
                            e.preventDefault();
                            var newdata = self.modConfigModel.toJSON();
                            var attrs = self.modConfigModel.changedAttributes();
                            if (attrs) {
                                self.modConfigModel.save(attrs, {patch: true});
                            }
                            return(false);
                        }
                    }
                });

                self.modConfigFormGeneral.render();

                // Add buttons for the standard messages in the various places.
                var sortmsgs = orderedMessages(self.modConfigModel.get('stdmsgs'), self.modConfigModel.get('messageorder'));
                self.$('.js-stdmsgspending, .js-stdmsgsapproved, .js-stdmsgspendingmembers, .js-stdmsgsmembers').empty();

                _.each(sortmsgs, function (stdmsg) {
                    // Find the right place to add the button.
                    var container = null;
                    switch (stdmsg.action) {
                        case 'Approve':
                        case 'Reject':
                        case 'Delete':
                        case 'Leave':
                        case 'Edit':
                            container = ".js-stdmsgspending";
                            break;
                        case 'Leave Approved Message':
                        case 'Delete Approved Message':
                            container = ".js-stdmsgsapproved";
                            break;
                        case 'Approve Member':
                        case 'Reject Member':
                        case 'Leave Member':
                            container = ".js-stdmsgspendingmembers";
                            break;
                        case 'Delete Approved Member':
                            container = ".js-stdmsgsmembers";
                        case 'Leave Approved Member':
                            break;
                    }

                    var v = new Iznik.Views.ModTools.StdMessage.SettingsButton({
                        model: new Iznik.Models.ModConfig.StdMessage(stdmsg),
                        config: self.modConfigModel
                    });

                    var el = v.render().el;
                    $(el).data('buttonid', stdmsg.id);
                    self.$(container).append(el);
                });

                // Make the buttons sortable.
                self.$('.js-sortable').each(function(index, value) {
                    Sortable. create(value, {
                        onEnd: function(evt) {
                            // We've dragged a button.  Find the New Order.
                            var order = [];
                            self.$('.js-stdbutton').each(function(index, button) {
                                var id = $(button).data('buttonid');
                                order.push(id);
                            });

                            // We have the New Order.  Undivided joy.
                            var neworder = JSON.stringify(order);
                            self.modConfigModel.set('messageorder', neworder);
                            self.modConfigModel.save({
                                'messageorder': neworder
                            }, {patch: true});
                        }
                    });
                });

                // Layout messes up a bit for radio buttons.
                self.$(':radio').closest('.form-group').addClass('clearfix');
            });
        }
    },

    render: function() {
        var self = this;

        Iznik.Views.Page.prototype.render.call(this);

        self.groupSelect = new Iznik.Views.Group.Select({
            systemWide: false,
            all: false,
            mod: true,
            choose: true,
            id: 'settingsGroupSelect'
        });

        self.listenTo(self.groupSelect, 'selected', function(selected) {
            self.selected = selected;
            self.settingsGroup();
        });

        // Render after the listen to as they are called during render.
        self.$('.js-groupselect').html(self.groupSelect.render().el);

        // Personal settings
        var me = Iznik.Session.get('me');

        self.personalModel = new IznikModel({
            id: me.id,
            displayname: me.displayname,
            fullname: me.fullname
        });

        var personalFields = [
            {
                name: 'displayname',
                label: 'Display Name',
                control: 'input',
                helpMessage: 'This is your name as displayed publicly to other users, including in the $myname substitution string.'
            },
            {
                control: 'button',
                label: 'Save changes',
                type: 'submit',
                extraClasses: [ 'btn-success' ]
            }
        ];

        var personalForm = new Backform.Form({
            el: $('#personalform'),
            model: self.personalModel,
            fields: personalFields,
            events: {
                'submit': function(e) {
                    e.preventDefault();
                    var newdata = self.personalModel.toJSON();
                    Iznik.Session.save(newdata, { patch: true });
                    return(false);
                }
            }
        });

        personalForm.render();

        var configs = Iznik.Session.get('configs');
        configs.each(function(config) {
            self.$('.js-configselect').append('<option value=' + config.get('id') + '>' +
                $('<div />').text(config.get('name')).html() + '</option>');
        });

        self.$(".js-configselect").val($(".js-configselect option:first").val());
        self.configSelect();

        // We seem to need to redelegate, otherwise the change event is not caught.
        self.delegateEvents();
    }
});

Iznik.Views.ModTools.StdMessage.SettingsButton = Iznik.Views.ModTools.StdMessage.Button.extend({
    // We override the events, so we get the same visual display but when we click do an edit of the settings.
    events: {
        'click .js-approve': 'edit',
        'click .js-reject': 'edit',
        'click .js-delete': 'edit',
        'click .js-hold': 'edit',
        'click .js-release': 'edit',
        'click .js-edit': 'edit'
    },

    edit: function() {
        var v = new Iznik.Views.ModTools.Settings.StdMessage({
            model: this.model
        });
        v.render();
    }
});

Iznik.Views.ModTools.Settings.StdMessage = Iznik.Views.Modal.extend({
    template: 'modtools_settings_stdmsg',

    events: {
        'click .js-save': 'save',
        'click .js-delete': 'delete'
    },

    save: function() {
        var self = this;

        self.model.save(
            self.model.changedAttributes(),
            {patch: true}
        ).then(function() {
                self.close();
        });
    },

    render: function() {
        var self = this;

        this.$el.html(window.template(this.template)(this.model.toJSON2()));

        // We want to refetch the model to make sure we edit the most up to date settings.
        self.model.fetch().then(function() {
            self.fields = [
                {
                    name: 'title',
                    label: 'Title',
                    control: 'input'
                },
                {
                    name: 'action',
                    label: 'Action',
                    control: 'select',
                    options: [
                        {
                            label: 'Approve Pending Message',
                            value: 'Approve'
                        },
                        {
                            label: 'Reject Pending Message',
                            value: 'Reject'
                        },
                        {
                            label: 'Reply to Pending Message',
                            value: 'Leave'
                        },
                        {
                            label: 'Edit Pending Message',
                            value: 'Edit'
                        },
                        {
                            label: 'Delete Approved Message',
                            value: 'Delete Approved Message'
                        },
                        {
                            label: 'Reply to Approved Message',
                            value: 'Leave Approved Message'
                        },
                        {
                            label: 'Reply to Pending Message',
                            value: 'Leave'
                        },
                        {
                            label: 'Approve Pending Member',
                            value: 'Approve Member'
                        },
                        {
                            label: 'Reject Pending Member',
                            value: 'Reject Member'
                        },
                        {
                            label: 'Mail Pending Member',
                            value: 'Leave Member'
                        },
                        {
                            label: 'Remove Member',
                            value: 'Delete Approved Member'
                        },
                        {
                            label: 'Mail Member',
                            value: 'Leave Approved Member'
                        }
                    ]
                },
                {
                    name: 'autosend',
                    label: 'Edit Text (only for Edits)',
                    options: [{label: 'Unchanged', value: 'Unchanged'}, {label: 'Correct Case', value: 'Correct Case' }],
                    disabled: function(model) { return(model.get('action') != 'Edit')},
                    control: Backform.SelectControl.extend({
                        initialize: function() {
                            Backform.InputControl.prototype.initialize.apply(this, arguments);
                            this.listenTo(this.model, "change:action", this.render);
                        }
                    })
                },
                {
                    name: 'autosend',
                    label: 'Autosend',
                    control: 'select',
                    options: [{label: 'Send immediately', value: 1}, {label: 'Edit before send', value: 0 }]
                },
                {
                    name: 'rarelyused',
                    label: 'How often do you use this?',
                    control: 'select',
                    options: [{label: 'Frequently', value: 1}, {label: 'Rarely', value: 0 }]
                },
                {
                    name: 'newmodstatus',
                    label: 'Change Yahoo Moderation Status?',
                    control: 'select',
                    options: [
                        {label: 'Unchanged', value: 'UNCHANGED'},
                        {label: 'Moderated', value: 'MODERATED'},
                        {label: 'Group Settings', value: 'DEFAULT'},
                        {label: 'Can\'t Post', value: 'PROHIBITED'},
                        {label: 'Unmoderated', value: 'UNMODERATED'},
                    ]
                },
                {
                    name: 'newdelstatus',
                    label: 'Change Yahoo Delivery Settings?',
                    control: 'select',
                    options: [
                        {label: 'Unchanged', value: 'UNCHANGED'},
                        {label: 'Daily Digest', value: 'DIGEST'},
                        {label: 'Web Only', value: 'NONE'},
                        {label: 'Individual Emails', value: 'SINGLE'},
                        {label: 'Special Notices', value: 'ANNOUNCEMENT'},
                    ]
                },
                {
                    name: 'subjpref',
                    label: 'Subject Prefix',
                    control: 'input'
                },
                {
                    name: 'subjsuff',
                    label: 'Subject Suffix',
                    control: 'input'
                },
                {
                    name: 'body',
                    label: 'Message Body',
                    control: 'textarea',
                    extraClasses: [ 'js-textarea' ]
                }
            ];

            self.form = new Backform.Form({
                el: $('#js-form'),
                model: self.model,
                fields: self.fields
            });

            self.form.render();

            // Layout messes up a bit.
            self.$('.form-group').addClass('clearfix');
            self.$('.js-textarea').attr('rows', 10);

            // Turn on spell-checking
            self.$('textarea, input:text').attr('spellcheck', true);
        });

        this.open(null);

        return(this);
    }
});

