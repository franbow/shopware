import { Component, Mixin } from 'src/core/shopware';
import { email } from 'src/core/service/validation.service';
import Criteria from 'src/core/data-new/criteria.data';
import CriteriaFactory from 'src/core/factory/criteria.factory';
import types from 'src/core/service/utils/types.utils';
import template from './sw-profile-index.html.twig';

Component.register('sw-profile-index', {
    template,

    inject: ['userService', 'loginService', 'repositoryFactory', 'context'],

    mixins: [
        Mixin.getByName('notification')
    ],

    data() {
        return {
            userProfile: {},
            user: { username: '', email: '' },
            languages: [],
            imageSize: 140,
            oldPassword: null,
            newPassword: null,
            newPasswordConfirm: null,
            avatarMediaItem: null,
            uploadTag: 'sw-profile-upload-tag',
            isLoading: false,
            isUserLoading: true,
            isSaveSuccessful: false
        };
    },

    metaInfo() {
        return {
            title: this.$createTitle()
        };
    },

    computed: {
        isDisabled() {
            return true; // TODO use ACL here with NEXT-1653
        },

        userRepository() {
            return this.repositoryFactory.create('user');
        },

        languageRepository() {
            return this.repositoryFactory.create('language');
        },

        localeRepository() {
            return this.repositoryFactory.create('locale');
        },

        mediaRepository() {
            return this.repositoryFactory.create('media');
        },

        userMediaCriteria() {
            if (this.user.id) {
                // ToDo: If SwSidebarMedia has the new data handling, change this too
                return CriteriaFactory.equals('userId', this.user.id);
            }

            return null;
        },

        languageId() {
            return this.$store.state.adminLocale.languageId;
        }
    },

    watch: {
        'user.avatarMedia'() {
            if (this.user.avatarMedia.id) {
                this.setMediaItem({ targetId: this.user.avatarMedia.id });
            }
        },

        languageId() {
            this.createdComponent();
        }
    },

    created() {
        this.createdComponent();
    },

    beforeMount() {
        this.beforeMountComponent();
    },

    methods: {
        createdComponent() {
            this.isUserLoading = true;

            const languagePromise = new Promise((resolve) => {
                resolve(this.languageId);
            });

            if (this.$route.params.user) {
                this.userPromise = this.setUserData(this.$route.params.user);
            } else {
                this.userPromise = this.userService.getUser().then((response) => {
                    return this.setUserData(response.data);
                });
            }
            const promises = [
                languagePromise,
                this.userPromise
            ];

            Promise.all(promises).then(() => {
                this.loadLanguages();
            }).then(() => {
                this.isUserLoading = false;
            });
        },

        beforeMountComponent() {
            this.userPromise.then((user) => {
                this.user = user;
            });
        },

        loadLanguages() {
            const languageCriteria = new Criteria();
            languageCriteria.addAssociation('locale');
            languageCriteria.addSorting(Criteria.sort('locale.name', 'ASC'));
            languageCriteria.addSorting(Criteria.sort('locale.territory', 'ASC'));
            languageCriteria.limit = 500;

            return this.languageRepository.search(languageCriteria, this.context).then((result) => {
                this.languages = [];
                result.forEach((lang) => {
                    lang.customLabel = `${lang.locale.translated.name} (${lang.locale.translated.territory})`;
                    this.languages.push(lang);
                });

                return this.languages;
            });
        },

        setUserData(userProfile) {
            this.userProfile = userProfile;
            return new Promise((resolve) => {
                resolve(this.userRepository.get(this.userProfile.id, this.context));
            });
        },

        saveFinish() {
            this.isSaveSuccessful = false;
        },

        onSave() {
            if (this.checkEmail() === false) {
                return;
            }
            this.isSaveSuccessful = false;
            this.isLoading = true;

            const passwordCheck = this.checkPassword();
            if (passwordCheck === null) {
                this.saveUser();
            } else {
                passwordCheck.then((validNewPassword) => {
                    if (validNewPassword) {
                        this.saveUser();
                    }
                });
            }
        },

        checkEmail() {
            if (!email(this.user.email)) {
                this.createErrorMessage(this.$tc('sw-profile.index.notificationInvalidEmailErrorMessage'));

                return false;
            }

            return true;
        },

        checkPassword() {
            if (this.newPassword && this.newPassword.length > 0) {
                return this.validateOldPassword().then((oldPasswordIsValid) => {
                    if (oldPasswordIsValid === false) {
                        this.createErrorMessage(this.$tc('sw-profile.index.notificationOldPasswordErrorMessage'));
                        return false;
                    }

                    if (this.oldPassword === this.newPassword) {
                        this.createErrorMessage(this.$tc('sw-profile.index.notificationNewPasswordIsSameAsOldErrorMessage'));
                        return false;
                    }

                    if (this.newPassword !== this.newPasswordConfirm) {
                        this.createErrorMessage(this.$tc('sw-profile.index.notificationPasswordErrorMessage'));
                        return false;
                    }

                    this.user.password = this.newPassword;

                    return true;
                });
            }

            return null;
        },

        validateOldPassword() {
            return this.loginService.loginByUsername(this.user.username, this.oldPassword).then((response) => {
                return types.isString(response.access);
            }).catch(() => {
                return false;
            });
        },

        createErrorMessage(errorMessage) {
            this.createNotificationError({
                title: this.$tc('sw-profile.index.notificationPasswordErrorTitle'),
                message: errorMessage
            });
        },

        saveUser() {
            this.userRepository.save(this.user, this.context).then(() => {
                this.$refs.mediaSidebarItem.getList();

                this.localeRepository.get(this.user.localeId, this.context).then(({ code }) => {
                    this.$store.dispatch('setAdminLocale', code);
                });

                this.oldPassword = '';
                this.newPassword = '';
                this.newPasswordConfirm = '';

                this.isLoading = false;
                this.isSaveSuccessful = true;
            }).catch(() => {
                this.isLoading = false;
            });
        },

        setMediaItem({ targetId }) {
            this.mediaRepository.get(targetId, this.context).then((response) => {
                this.avatarMediaItem = response;
            });
            this.user.avatarId = targetId;
        },

        onDropMedia(mediaItem) {
            this.setMediaItem({ targetId: mediaItem.id });
        },

        setMediaFromSidebar(mediaEntity) {
            this.avatarMediaItem = mediaEntity;
            this.user.avatarId = mediaEntity.id;
        },

        onUnlinkAvatar() {
            this.avatarMediaItem = null;
            this.user.avatarId = null;
        },

        openMediaSidebar() {
            this.$refs.mediaSidebarItem.openContent();
        }
    }
});
