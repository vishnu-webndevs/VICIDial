export type AuthUser = {
  id: string;
  email: string;
  first_name: string;
  last_name: string;
};

export type AuthTenant = {
  id: string;
  name: string;
  slug: string;
  status: string;
};

export type RegisterResponse = {
  data: {
    token: string;
    user: AuthUser;
    tenant: AuthTenant;
    membership: {
      id: string;
      status: string;
      role: string;
    };
  };
};

export type LoginResponse = {
  data: {
    token: string;
    user: AuthUser & {
      last_login_at: string | null;
    };
  };
};
