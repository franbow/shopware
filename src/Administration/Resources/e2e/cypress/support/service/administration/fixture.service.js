const _ = require('lodash');
const uuid = require('uuid/v4');
const AdminApiService = require('./admin-api.service');

export default class AdminFixtureService {
    constructor() {
        this.apiClient = new AdminApiService();
    }

    create(endpoint, rawData) {
        return this.apiClient.post(`/v1/${endpoint}?response=true`, rawData);
    }

    update(userData) {
        if (!userData.id) {
            throw new Error('Update fixtures must always contain an id');
        }
        return this.apiClient.patch(`/v1/${userData.type}/${userData.id}?_response=true`, userData.data);
    }

    authenticate() {
        return this.apiClient.loginToAdministration();
    }

    search(type, filter) {
        return this.apiClient.post(`/v1/search/${type}?response=true`, {
            filter: [{
                field: filter.field ? filter.field : 'name',
                type: 'equals',
                value: filter.value
            }]
        });
    }

    createUuid() {
        return uuid();
    }

    mergeFixtureWithData(...args) {
        return _.merge({}, ...args);
    }
}

global.AdminFixtureService = new AdminFixtureService();
