export type InsurerClient = {
  name: string;
  logo?: string;
  logoAlt?: string;
};

export const insurerClients: InsurerClient[] = [
  { name: 'Sparkassenversicherung', logo: '/assets/images/insurers/sparkassenversicherung.png', logoAlt: 'Logo Sparkassenversicherung' },
  { name: 'R+V Versicherung', logo: '/assets/images/insurers/ruv-versicherung.svg', logoAlt: 'Logo R+V Versicherung' },
  { name: 'ERGO', logo: '/assets/images/insurers/ergo.svg', logoAlt: 'Logo ERGO' },
  { name: 'Württembergische', logo: '/assets/images/insurers/wuerttembergische.svg', logoAlt: 'Logo Württembergische' },
  { name: 'LVM', logo: '/assets/images/insurers/lvm.svg', logoAlt: 'Logo LVM' },
  { name: 'Concordia', logo: '/assets/images/insurers/concordia.png', logoAlt: 'Logo Concordia' },
  { name: 'Alte Leipziger' },
  { name: 'Helvetia', logo: '/assets/images/insurers/helvetia.svg', logoAlt: 'Logo Helvetia' },
  { name: 'Provinzial', logo: '/assets/images/insurers/provinzial.svg', logoAlt: 'Logo Provinzial' },
  { name: 'Ecclesia Gruppe', logo: '/assets/images/insurers/ecclesia-gruppe.png', logoAlt: 'Logo Ecclesia Gruppe' },
];
