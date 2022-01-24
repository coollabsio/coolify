/// <reference types="@sveltejs/kit" />
interface Cookies {
    teamId?: string;
    gitlabToken?: string;
    'kit.session'?: string;
}
interface Locals {
    user: {
        teamId: string;
        permission: string;
        isAdmin: boolean;
    }
    session: {
        data: {
            token?: string;
            uid?: string;
            teams?: string[];
            expires?: string;
        }
    }
}

type Applications = {
    name: string;
    domain: string;
};

interface Hash {
    iv: string;
    content: string;
}

interface BuildPack {
    name: string;
}

// TODO: Not used, not working what?!
enum GitSource {
    Github = 'github', 
    Gitlab = 'gitlab', 
    Bitbucket = 'bitbucket'
}
interface NewGitSource {
    name: string,
    type: string,
    htmlUrl: string,
    apiUrl: string,
    organization?: string
    applicationType?: string
}

interface AppConfiguration {
    configuration: any
}

type RawHaproxyConfiguration = {
    _version: number;
    data: string;
}

type NewTransaction = {
    _version: number;
    id: string;
    status: string;
}


type HttpRequestRuleForceSSL = {
    return_hdrs: null;
    cond: string;
    cond_test: string;
    index: number;
    redir_code: number;
    redir_type: string;
    redir_value: string;
    type: string;
}

// TODO: No any please
type HttpRequestRule = {
    _version: number;
    data: Array<any>;
}

type DateTimeFormatOptions = {
	localeMatcher?: 'lookup' | 'best fit';
	weekday?: 'long' | 'short' | 'narrow';
	era?: 'long' | 'short' | 'narrow';
	year?: 'numeric' | '2-digit';
	month?: 'numeric' | '2-digit' | 'long' | 'short' | 'narrow';
	day?: 'numeric' | '2-digit';
	hour?: 'numeric' | '2-digit';
	minute?: 'numeric' | '2-digit';
	second?: 'numeric' | '2-digit';
	timeZoneName?: 'long' | 'short';
	formatMatcher?: 'basic' | 'best fit';
	hour12?: boolean;
	timeZone?: string;
};