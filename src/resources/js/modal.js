if (typeof Craft.EntryTemplates === typeof undefined) {
  Craft.EntryTemplates = {};
}

Craft.EntryTemplates.Modal = Garnish.Base.extend({
   $settings: null,
   $elements: {},
   $default: {},

    init(settings) {
        Object.defineProperty(this.$elements, "__esModule", {
            value: !0
        });

        this.$settings = settings;
        this.$elements.default = class {
            constructor(t) {
                var e;
                this.id = t.id,
                this.title = t.title,
                this.preview = null !== (e = t.preview) && void 0 !== e ? e : 1,
                this.description = t.description,
                this.$button = $(this._button())
            }
            _button() {
                const t = $("<button />").append($("<p />").text(this.title)),
                    e = $("<div />").appendTo(t);

                switch (this.preview) {
                    case 1:
                        t.addClass("is-empty");
                        break;
                    default:
                        e.append($("<img />").attr("src", this.preview))
                }

                if (this.description !== null) {
                    t.append($('<p class="description" />').text(this.description))
                }
                return t;
            }
        };

        this._addDefault();
        this._createModal();
    },
    _button() {
        const t = $("<button />").append($("<p />").text(this.title)),
            e = $("<div />").appendTo(t);

        return t;
    },
    _footer() {
        let footerBar = document.createElement("div");
        footerBar.classList.add('buttons', 'right');

        let cancelButton = document.createElement('button');
        cancelButton.setAttribute('type', 'button');
        cancelButton.setAttribute('tabindex', '0');
        cancelButton.classList.add('btn', 'template-cancel-btn');
        cancelButton.innerText = 'Cancel';

        footerBar.append(cancelButton);

        return footerBar;
    },
    _addDefault() {
        Object.defineProperty(this.$default, "__esModule", {
            value: !0
        });

        const a = {
            0: 'Blank',
            'Blank': 0,
        };

        this.$default.default = a;

    },
    _createModal() {

        // Create Modal
        const n = $('<div class="modal templates_modal" />');
        this.garnishModal = new Garnish.Modal(n);

        // Hide Modal
        this.garnishModal.hide();

        // Show Modal
        const templateButton = document.querySelector('button.template-modal');
        const self = this;
        templateButton.addEventListener('click', function () {
            self.garnishModal.show();
        });

        const s = $('<div class="modal_body" />').appendTo(n).append($('<h2 class="modal_heading" />').text(Craft.t("entrytemplates", "Choose a template"))),
            o = $('<div class="modal_container" />').appendTo(s),
            m = $('<div class="modal_inner" />').appendTo(o);

        // Add Footer
        const footer = $('<div class="footer" />').append(this._footer()).appendTo(o);
        const cancelButton = document.querySelector('button.template-cancel-btn');

        if (cancelButton) {
            cancelButton.addEventListener('click', function () {
                self.garnishModal.hide();
            });
        }

        const elements = this.$elements;
        const settings = this.$settings;
        const defaults = this.$default;

        // Add Content Blocks
        this.contentTemplates = [],

        this.contentTemplates.push(...settings.entryTemplates.map((defaults => new elements.default(defaults))));

        this.contentTemplates.forEach((t => {

            $('<div class="modal_block" />').append(t.$button).appendTo(m), t.$button.on("activate", (e => {
                if (void 0 === t.id) this.garnishModal.hide();
                else {
                    if (confirm('Are you sure you want to apply this template? It will remove any content you may have already added.')) {
                        n.addClass("applying");
                        const e = {
                            elementId: settings.elementId,
                            entryTemplateId: t.id
                        };
                        Craft.sendActionRequest("POST", "entrytemplates/templates/apply", {
                            data: e
                        }).then((t => {
                            window.location.href = t.data.redirect
                        })).catch((t => {
                            var e;
                            n.removeClass("applying"), Craft.cp.displayError(null !== (e = t.error) && void 0 !== e ? e : Craft.t("entrytemplates", "An unknown error occurred."))
                        }))
                    }
                }
            }))
        }));
    }
});
