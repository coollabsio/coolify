/// <reference types="@sveltejs/kit" />
interface Locals {
    session: {
        data: {
            token?: string;
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

